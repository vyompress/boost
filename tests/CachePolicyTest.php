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

	public function testDetectsPrivateSessionCookies(): void {
		self::assertTrue($this->policy->hasBypassCookie(array('wordpress_logged_in_hash' => 'token')));
		self::assertTrue($this->policy->hasBypassCookie(array('wp_woocommerce_session_hash' => 'token')));
		self::assertFalse($this->policy->hasBypassCookie(array('wordpress_test_cookie' => 'value')));
	}
}
