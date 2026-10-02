<?php
/**
 * Cache-store unit tests.
 */

declare(strict_types=1);

namespace VyomPress\Boost\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VyomPress\Boost\Cache\CacheKey;
use VyomPress\Boost\Cache\CacheStore;

final class CacheStoreTest extends TestCase {
	private string $directory;
	private CacheStore $store;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/vyompress-boost-' . bin2hex(random_bytes(8));
		$this->store     = new CacheStore($this->directory);
	}

	protected function tearDown(): void {
		if (! is_dir($this->directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($this->directory);
	}

	public function testWritesReadsCountsAndClearsEntries(): void {
		$key  = CacheKey::make('https', 'example.com', '/', 'desktop');
		$html = '<!doctype html><html><body>Cached</body></html>';

		self::assertTrue($this->store->write($key, $html));
		self::assertSame($html, $this->store->read($key, 3600));
		self::assertSame(1, $this->store->count());
		self::assertSame(1, $this->store->clear());
		self::assertSame(0, $this->store->count());
		self::assertFileExists($this->directory . '/index.php');
	}

	public function testExpiredEntryIsRemoved(): void {
		$key = CacheKey::make('https', 'example.com', '/old/', 'desktop');
		self::assertTrue($this->store->write($key, '<html></html>'));

		$path = $this->directory . '/' . substr($key, 0, 2) . '/' . $key . '.html';
		touch($path, time() - 120);
		clearstatcache(true, $path);

		self::assertNull($this->store->read($key, 60));
		self::assertFileDoesNotExist($path);
	}

	public function testRejectsMalformedCacheKey(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->store->read('../unsafe', 60);
	}
}
