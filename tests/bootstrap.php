<?php
/**
 * Unit-test bootstrap for WordPress-independent cache primitives.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

defined('WEEK_IN_SECONDS') || define('WEEK_IN_SECONDS', 604800);
defined('YEAR_IN_SECONDS') || define('YEAR_IN_SECONDS', 31536000);
defined('MONTH_IN_SECONDS') || define('MONTH_IN_SECONDS', 2592000);

$GLOBALS['vyompress_boost_test_options'] = array();

if (! function_exists('get_option')) {
	/**
	 * Minimal option reader test double.
	 */
	function get_option(string $key, mixed $default = false): mixed {
		return $GLOBALS['vyompress_boost_test_options'][$key] ?? $default;
	}
}

if (! function_exists('absint')) {
	function absint(mixed $value): int {
		return abs((int) $value);
	}
}

if (! function_exists('wp_unslash')) {
	function wp_unslash(mixed $value): mixed {
		return $value;
	}
}

if (! function_exists('sanitize_text_field')) {
	function sanitize_text_field(string $value): string {
		return trim(strip_tags($value));
	}
}

if (! function_exists('esc_url_raw')) {
	function esc_url_raw(string $url): string {
		return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
	}
}

if (! function_exists('untrailingslashit')) {
	function untrailingslashit(string $value): string {
		return rtrim($value, '/\\');
	}
}

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

if (! function_exists('wp_delete_file')) {
	/**
	 * Minimal WordPress file deletion test double.
	 */
	function wp_delete_file(string $file): void {
		if (is_file($file)) {
			unlink($file);
		}
	}
}

require_once dirname(__DIR__) . '/src/Cache/CacheKey.php';
require_once dirname(__DIR__) . '/src/Cache/CachePolicy.php';
require_once dirname(__DIR__) . '/src/Cache/CacheStore.php';
require_once dirname(__DIR__) . '/src/Cloudflare/CloudflareClient.php';
require_once dirname(__DIR__) . '/src/Media/S3Signer.php';
require_once dirname(__DIR__) . '/src/Settings.php';
