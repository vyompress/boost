<?php
/**
 * Cache-policy unit tests.
 */

declare(strict_types=1);

namespace VyomPress\Boost\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VyomPress\Boost\Cache\CachePolicy;

final class CachePolicyTest extends TestCase {
	private CachePolicy $policy;

	protected function setUp(): void {
		$this->policy = new CachePolicy();
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function methodProvider(): array {
		return array(
			'get'    => array('GET', true),
			'head'   => array('HEAD', true),
			'lower'  => array('get', true),
			'post'   => array('POST', false),
			'delete' => array('DELETE', false),
		);
	}

	#[DataProvider('methodProvider')]
	public function testAllowsOnlySafeReadMethods(string $method, bool $expected): void {
		self::assertSame($expected, $this->policy->allowsMethod($method));
	}

	public function testDetectsQueryStrings(): void {
		self::assertTrue($this->policy->hasQueryString('/articles/?preview=true'));
		self::assertFalse($this->policy->hasQueryString('/articles/'));
	}

	public function testNormalizesTrackingParameters(): void {
		self::assertSame(
			'/articles/',
			$this->policy->normalizedUri('/articles/?utm_source=newsletter&fbclid=abc', false, array('utm_source', 'fbclid'))
		);
	}

	public function testMeaningfulQueryParametersRequireOptInAndAreSorted(): void {
		self::assertNull($this->policy->normalizedUri('/shop/?size=large&color=blue', false, array()));
		self::assertSame('/shop/?color=blue&size=large', $this->policy->normalizedUri('/shop/?size=large&color=blue', true, array()));
	}

	public function testMatchesConfiguredPathPrefixes(): void {
		self::assertTrue($this->policy->isExcludedPath('/checkout/order-pay/12/', array('/cart/', '/checkout/')));
		self::assertFalse($this->policy->isExcludedPath('/shop/checkout-guide/', array('/checkout/')));
	}

	public function testDetectsPrivateSessionCookies(): void {
		self::assertTrue($this->policy->hasBypassCookie(array('wordpress_logged_in_hash' => 'token')));
		self::assertTrue($this->policy->hasBypassCookie(array('wp_woocommerce_session_hash' => 'token')));
		self::assertFalse($this->policy->hasBypassCookie(array('wordpress_test_cookie' => 'value')));
	}
}
