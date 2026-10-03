<?php
/**
 * Settings validation tests.
 */

declare(strict_types=1);

namespace VyomPress\Boost\Tests;

use PHPUnit\Framework\TestCase;
use VyomPress\Boost\Settings;

final class SettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['vyompress_boost_test_options'][Settings::OPTION] = array_merge(
			Settings::defaults(),
			array(
				'cloudflare_api_token' => 'existing-token',
				's3_secret_key'        => 'existing-secret',
			)
		);
	}

	public function testPartialTabSubmissionPreservesOtherSettingsAndSecrets(): void {
		$result = Settings::sanitize(
			array(
				'page_cache' => '0',
				'cache_ttl'  => '900',
			)
		);

		self::assertFalse($result['page_cache']);
		self::assertSame(900, $result['cache_ttl']);
		self::assertSame('existing-token', $result['cloudflare_api_token']);
		self::assertSame('existing-secret', $result['s3_secret_key']);
	}

	public function testExplicitCredentialClearAndInputNormalization(): void {
		$result = Settings::sanitize(
			array(
				'clear_s3_secret_key'       => '1',
				'cloudflare_zone_id'        => 'ABC-123<script>',
				'ignored_query_parameters'  => "utm_source\ninvalid name\nfbclid\nutm_source",
				'excluded_paths'             => "checkout/\nhttps://example.com/cart/",
			)
		);

		self::assertSame('', $result['s3_secret_key']);
		self::assertSame('ABC123', $result['cloudflare_zone_id']);
		self::assertSame("utm_source\nfbclid", $result['ignored_query_parameters']);
		self::assertSame("/checkout/\n/cart/", $result['excluded_paths']);
	}

	public function testCacheLifetimesAreBounded(): void {
		$result = Settings::sanitize(array('cache_ttl' => 99999999, 'browser_ttl' => 1));

		self::assertSame(WEEK_IN_SECONDS, $result['cache_ttl']);
		self::assertSame(60, $result['browser_ttl']);
	}
}
