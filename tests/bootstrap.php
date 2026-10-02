<?php
/**
 * Unit-test bootstrap for WordPress-independent cache primitives.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (! function_exists('wp_parse_url')) {
	/**
	 * Minimal WordPress URL parser test double.
	 */
	function wp_parse_url(string $url, int $component = -1): string|int|array|false|null {
		return parse_url($url, $component);
	}
}

if (! function_exists('wp_mkdir_p')) {
	/**
	 * Minimal recursive directory creation test double.
	 */
	function wp_mkdir_p(string $target): bool {
		return is_dir($target) || mkdir($target, 0777, true);
	}
}

if (! function_exists('wp_generate_password')) {
	/**
	 * Deterministic-shape random password test double.
	 */
	function wp_generate_password(int $length = 12): string {
		return substr(bin2hex(random_bytes($length)), 0, $length);
	}
}

require_once dirname(__DIR__) . '/src/Cache/CacheKey.php';
require_once dirname(__DIR__) . '/src/Cache/CachePolicy.php';
require_once dirname(__DIR__) . '/src/Cache/CacheStore.php';
