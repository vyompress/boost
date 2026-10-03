<?php
/**
 * S3 Signature Version 4 tests.
 */

declare(strict_types=1);

namespace VyomPress\Boost\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VyomPress\Boost\Media\S3Signer;

final class S3SignerTest extends TestCase {
	public function testProducesStableScopedAuthorization(): void {
		$signer  = new S3Signer();
		$headers = $signer->sign(
			'PUT',
			'https://s3.example.com/media/site/image%20one.jpg',
			array('Content-Type' => 'image/jpeg'),
			hash('sha256', 'image-bytes'),
			'ACCESSKEY',
			'secret-key',
			'us-east-1',
			new DateTimeImmutable('2026-10-03 10:20:30', new DateTimeZone('UTC'))
		);

		self::assertSame('s3.example.com', $headers['Host']);
		self::assertSame('20261003T102030Z', $headers['X-Amz-Date']);
		self::assertStringStartsWith(
			'AWS4-HMAC-SHA256 Credential=ACCESSKEY/20261003/us-east-1/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=',
			$headers['Authorization']
		);
		self::assertMatchesRegularExpression('/Signature=[a-f0-9]{64}$/', $headers['Authorization']);
	}

	public function testSignatureChangesWithPayload(): void {
		$signer = new S3Signer();
		$now    = new DateTimeImmutable('2026-10-03 10:20:30', new DateTimeZone('UTC'));
		$first  = $signer->sign('PUT', 'https://s3.example.com/bucket/file.txt', array(), hash('sha256', 'first'), 'key', 'secret', 'auto', $now);
		$second = $signer->sign('PUT', 'https://s3.example.com/bucket/file.txt', array(), hash('sha256', 'second'), 'key', 'secret', 'auto', $now);

		self::assertNotSame($first['Authorization'], $second['Authorization']);
	}
}
