=== VyomPress Boost ===
Contributors: vyompress
Tags: cache, performance, optimization, speed, core web vitals
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe page caching and focused performance optimizations with no account, tracking, or external service required.

== Description ==

VyomPress Boost improves WordPress performance with conservative page caching and independently configurable optimizations.

The page cache serves complete HTML responses to anonymous visitors. It automatically bypasses logged-in users, previews, searches, feeds, error pages, query-string URLs, password-protected content, commenters, and common ecommerce sessions. Cached pages are cleared when content, comments, themes, plugins, or Customizer settings change.

Features:

* Anonymous HTML page cache with configurable lifetime.
* Automatic cache invalidation and a manual purge action.
* Browser cache headers for validated cache hits.
* Optional removal of WordPress emoji assets.
* Optional removal of front-end embed assets.
* Optional reduction of Heartbeat API frequency.
* Site Health integration for cache storage.
* No account, telemetry, advertisements, or external requests.

Source code, development documentation, and issue tracking are available at https://github.com/vyompress/boost.

== Installation ==

1. Upload the `vyompress-boost` directory to `/wp-content/plugins/`, or install the plugin through WordPress.
2. Activate VyomPress Boost.
3. Open Settings > VyomPress Boost.
4. Review the defaults and purge any upstream CDN after changing cache settings.

== Frequently Asked Questions ==

= Does the plugin send data to VyomPress? =

No. Version 0.1.0 makes no external requests and collects no telemetry.

= Does it cache logged-in or ecommerce sessions? =

No. Requests with WordPress login, password, commenter, WooCommerce, Easy Digital Downloads, or PHP session cookies bypass the page cache.

= Where are cached pages stored? =

Cached HTML is stored under `wp-content/cache/vyompress-boost` in an opaque, sharded file structure protected from direct web access.

= How can another plugin bypass the cache? =

Define the standard `DONOTCACHEPAGE` constant as true, send a private or no-store Cache-Control response, set a cookie, or use the `vyompress_boost_is_cacheable_request` filter.

== Changelog ==

= 0.1.0 =

* Initial public release.
* Added anonymous page caching and automatic invalidation.
* Added browser cache headers and safe WordPress asset optimizations.
* Added settings, manual purge, and Site Health integration.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
