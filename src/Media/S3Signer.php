<?php
/**
 * AWS Signature Version 4 implementation for S3-compatible APIs.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Media;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Produces deterministic SigV4 request headers without a vendor SDK.
 */
final class S3Signer {
	/**
	 * Create a time-limited query-signed object URL.
	 *
	 * @param string                 $method     HTTP method.
	 * @param string                 $url        Fully-qualified object URL.
	 * @param string                 $access_key S3 access key.
	 * @param string                 $secret_key S3 secret key.
	 * @param string                 $region     Signing region.
	 * @param int                    $expires    Lifetime in seconds.
	 * @param DateTimeImmutable|null $now        Optional clock for tests.
	 * @throws InvalidArgumentException When the request URL is not absolute.
	 */
	public function presign(
		string $method,
		string $url,
		string $access_key,
		string $secret_key,
		string $region,
		int $expires,
		?DateTimeImmutable $now = null
	): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			throw new InvalidArgumentException( 'A valid absolute S3 request URL is required.' );
		}

		$now      = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$amz_date = $now->format( 'Ymd\THis\Z' );
		$date     = $now->format( 'Ymd' );
		$scope    = $date . '/' . $region . '/s3/aws4_request';
		$host     = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$path     = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query    = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $access_key . '/' . $scope,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) max( 1, min( 604800, $expires ) ),
			'X-Amz-SignedHeaders' => 'host',
		);
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $existing );
			$query = array_merge( $existing, $query );
		}
		ksort( $query );
		$canonical_query   = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$canonical_request = strtoupper( $method ) . "\n" . $path . "\n" . $canonical_query . "\n" . 'host:' . $host . "\n\n" . 'host' . "\nUNSIGNED-PAYLOAD";
		$string            = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signature         = hash_hmac( 'sha256', $string, $this->signingKey( $secret_key, $date, $region ) );

		$authority = $parts['scheme'] . '://' . $host;

		return $authority . $path . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Sign one S3 request.
	 *
	 * @param string                 $method       HTTP method.
	 * @param string                 $url          Fully-qualified request URL.
	 * @param array<string,string>   $headers      Request headers.
	 * @param string                 $payload_hash SHA-256 hash of the request body.
	 * @param string                 $access_key   S3 access key.
	 * @param string                 $secret_key   S3 secret key.
	 * @param string                 $region       Signing region.
	 * @param DateTimeImmutable|null $now         Optional clock for tests.
	 * @return array<string,string>
	 * @throws InvalidArgumentException When the request URL is not absolute.
	 */
	public function sign(
		string $method,
		string $url,
		array $headers,
		string $payload_hash,
		string $access_key,
		string $secret_key,
		string $region,
		?DateTimeImmutable $now = null
	): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			throw new InvalidArgumentException( 'A valid absolute S3 request URL is required.' );
		}

		$now      = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$amz_date = $now->format( 'Ymd\THis\Z' );
		$date     = $now->format( 'Ymd' );
		$host     = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

		$headers['Host']                 = $host;
		$headers['X-Amz-Date']           = $amz_date;
		$headers['X-Amz-Content-Sha256'] = $payload_hash;

		$canonical_headers = array();
		foreach ( $headers as $name => $value ) {
			$canonical_headers[ strtolower( trim( $name ) ) ] = preg_replace( '/\s+/', ' ', trim( $value ) ) ?? '';
		}
		ksort( $canonical_headers );

		$canonical_header_string = '';
		foreach ( $canonical_headers as $name => $value ) {
			$canonical_header_string .= $name . ':' . $value . "\n";
		}
		$signed_headers  = implode( ';', array_keys( $canonical_headers ) );
		$canonical_uri   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$canonical_query = $this->canonicalQuery( (string) ( $parts['query'] ?? '' ) );

		$canonical_request = strtoupper( $method ) . "\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. $canonical_header_string . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		$scope       = $date . '/' . $region . '/s3/aws4_request';
		$string      = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signing_key = $this->signingKey( $secret_key, $date, $region );
		$signature   = hash_hmac( 'sha256', $string, $signing_key );

		$headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $access_key . '/' . $scope
			. ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

		return $headers;
	}

	/**
	 * Derive the binary SigV4 signing key.
	 *
	 * @param string $secret_key Secret access key.
	 * @param string $date       UTC date in YYYYMMDD format.
	 * @param string $region     Signing region.
	 */
	private function signingKey( string $secret_key, string $date, string $region ): string {
		$date_key    = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$region_key  = hash_hmac( 'sha256', $region, $date_key, true );
		$service_key = hash_hmac( 'sha256', 's3', $region_key, true );

		return hash_hmac( 'sha256', 'aws4_request', $service_key, true );
	}

	/**
	 * Sort and RFC 3986 encode a query string.
	 *
	 * @param string $query Raw URL query string.
	 */
	private function canonicalQuery( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		parse_str( $query, $parameters );
		ksort( $parameters );

		return http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 );
	}
}
