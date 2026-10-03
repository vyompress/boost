<?php
/**
 * Cloudflare cache-rule safety tests.
 */

declare(strict_types=1);

namespace VyomPress\Boost\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VyomPress\Boost\Cloudflare\CloudflareClient;

final class CloudflareClientTest extends TestCase {
	public function testEdgeRuleIncludesWordPressAndCommerceSafeguards(): void {
		$method = new ReflectionMethod( CloudflareClient::class, 'edgeRule' );
		$method->setAccessible( true );
		$rule = $method->invoke(
			new CloudflareClient(),
			'example.com',
			3600,
			array( '/checkout/', '/members/' ),
			false,
			true
		);

		self::assertSame( 'set_cache_settings', $rule['action'] );
		self::assertSame( 7200, $rule['action_parameters']['edge_ttl']['default'] );
		self::assertTrue( $rule['action_parameters']['cache_key']['cache_by_device_type'] );
		self::assertStringContainsString( 'wordpress_logged_in_', $rule['expression'] );
		self::assertStringContainsString( 'woocommerce_items_in_cart', $rule['expression'] );
		self::assertStringContainsString( '/checkout/', $rule['expression'] );
		self::assertStringContainsString( 'http.request.uri.query eq ""', $rule['expression'] );
	}
}
