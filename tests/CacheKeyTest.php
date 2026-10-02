<?php
/**
 * Cache-key unit tests.
 */

declare(strict_types=1);

namespace VyomPress\Boost\Tests;

use PHPUnit\Framework\TestCase;
use VyomPress\Boost\Cache\CacheKey;

final class CacheKeyTest extends TestCase {
	public function testEquivalentHostAndSchemeCasingProducesSameKey(): void {
		$first  = CacheKey::make('HTTPS', 'Example.COM', '/articles/', 'Desktop');
		$second = CacheKey::make('https', 'example.com', '/articles/', 'desktop');

		self::assertSame($first, $second);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
	}

	public function testRepresentationVariantsDoNotCollide(): void {
		$desktop = CacheKey::make('https', 'example.com', '/', 'desktop');
		$mobile  = CacheKey::make('https', 'example.com', '/', 'mobile');

		self::assertNotSame($desktop, $mobile);
	}
}
