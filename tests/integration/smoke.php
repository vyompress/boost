<?php
/**
 * Live WordPress integration smoke test.
 */

$base_url = 'http://wordpress';
$headers  = array( 'Host' => 'localhost:18080' );

$settings                  = get_option( 'vyompress_boost_settings', array() );
$settings['browser_cache'] = true;
update_option( 'vyompress_boost_settings', $settings );

/**
 * Fail the smoke test with a useful message.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 */
function vyompress_boost_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/**
 * Request a URL and require a successful response.
 *
 * @param string               $url     Request URL.
 * @param array<string, mixed> $options Request options.
 * @return array<string, mixed>
 */
function vyompress_boost_request( string $url, array $options ): array {
	$response = wp_remote_get( $url, $options );
	vyompress_boost_assert( ! is_wp_error( $response ), 'HTTP request failed.' );
	vyompress_boost_assert( 200 === wp_remote_retrieve_response_code( $response ), 'Expected HTTP 200.' );

	return $response;
}

$first = vyompress_boost_request( $base_url . '/', array( 'headers' => $headers ) );
vyompress_boost_assert( 'MISS' === wp_remote_retrieve_header( $first, 'x-vyompress-cache' ), 'First anonymous request must miss.' );
vyompress_boost_assert( str_contains( wp_remote_retrieve_body( $first ), 'VyomPress Boost Test' ), 'Homepage did not render.' );

$second = vyompress_boost_request( $base_url . '/', array( 'headers' => $headers ) );
vyompress_boost_assert( 'HIT' === wp_remote_retrieve_header( $second, 'x-vyompress-cache' ), 'Second anonymous request must hit.' );
vyompress_boost_assert( str_contains( wp_remote_retrieve_header( $second, 'cache-control' ), 'public' ), 'Cache hit must be publicly cacheable.' );

$query = vyompress_boost_request( $base_url . '/?cache-bypass=1', array( 'headers' => $headers ) );
vyompress_boost_assert( '' === wp_remote_retrieve_header( $query, 'x-vyompress-cache' ), 'Query-string request must bypass.' );

$private_headers           = $headers;
$private_headers['Cookie'] = 'wordpress_logged_in_smoke=private';
$private                    = vyompress_boost_request( $base_url . '/', array( 'headers' => $private_headers ) );
vyompress_boost_assert( '' === wp_remote_retrieve_header( $private, 'x-vyompress-cache' ), 'Session request must bypass.' );

$rest = vyompress_boost_request( $base_url . '/wp-json/', array( 'headers' => $headers ) );
vyompress_boost_assert( '' === wp_remote_retrieve_header( $rest, 'x-vyompress-cache' ), 'REST request must bypass.' );

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Cache invalidation test',
		'post_content' => 'Publishing must purge the page cache.',
		'post_status'  => 'publish',
	)
);
vyompress_boost_assert( ! is_wp_error( $post_id ), 'Unable to create invalidation post.' );

$after_update = vyompress_boost_request( $base_url . '/', array( 'headers' => $headers ) );
vyompress_boost_assert( 'MISS' === wp_remote_retrieve_header( $after_update, 'x-vyompress-cache' ), 'Content update must purge the cache.' );

fwrite( STDOUT, "PASS: VyomPress Boost WordPress integration smoke test.\n" );
