=== VyomPress Boost ===
Contributors: vyompress
Tags: cache, performance, cloudflare, s3, media
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 0.3.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast page caching, Cloudflare automation, and S3-compatible media offloading with a setup anyone can understand.

== Description ==

VyomPress Boost combines practical WordPress performance tools in one focused plugin. Its conservative defaults work immediately, while optional cloud integrations remain off until an administrator configures and enables them.

= Page cache =

* Anonymous HTML page caching with automatic invalidation.
* Separate page and browser cache lifetimes.
* Optional mobile cache variants.
* Tracking-parameter normalization and opt-in query-string caching.
* Editable path exclusions with safe store defaults.
* Automatic bypasses for logged-in users, previews, searches, feeds, error pages, REST requests, password-protected content, commenters, and common ecommerce sessions.
* Throttled sitemap-based cache preloading with safe URL and concurrency limits.

= Cloudflare =

* Scoped API-token authentication.
* Debounced automatic edge-cache purges after site changes.
* One-click connection test and manual purge.
* Optional plugin-owned Cloudflare Cache Rule for full-page edge caching with WordPress, login, REST, cookie, store, path, query, status-code, and device safeguards.
* API tokens can be supplied through `wp-config.php` instead of the database.

= S3-compatible media storage =

* Offloads new originals and generated image sizes.
* Works with AWS S3 and compatible services such as Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2 S3, and MinIO.
* Path-style and virtual-host-style endpoints.
* Optional custom CDN/public URL and object prefix.
* Safe default keeps local copies; local removal is explicitly opt-in.
* Deletes registered remote objects when their attachment is permanently deleted.
* Resumable background jobs to offload existing media, verify remote objects, and restore missing local copies.
* Access credentials can be supplied through `wp-config.php`.

= Simple operations =

* Guided, responsive settings screen with plain-language status cards.
* Connection tests that explain failures without exposing credentials.
* Site Health checks for cache storage and integration completeness.
* Local activity history for purges, preloads, Cloudflare operations, and media jobs.
* Optional emoji, embed, and Heartbeat optimizations.
* No telemetry, advertisements, or VyomPress account.

Source code, development documentation, and issue tracking are available at https://github.com/vyompress/boost.

== Installation ==

1. Install and activate VyomPress Boost.
2. Open Settings > VyomPress Boost.
3. Page caching is enabled with conservative defaults.
4. Optionally connect Cloudflare or S3-compatible media storage from their dedicated tabs.
5. Save the connection settings, then use the provided connection test.

== Frequently Asked Questions ==

= Does the plugin send data to VyomPress? =

No. VyomPress Boost contains no telemetry and makes no requests to VyomPress.

= Are cloud services required? =

No. Page caching and WordPress optimizations work locally. Cloudflare and media offloading are optional and disabled by default.

= Can credentials be kept out of the WordPress database? =

Yes. Define `VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN`, `VYOMPRESS_BOOST_S3_ACCESS_KEY`, and `VYOMPRESS_BOOST_S3_SECRET_KEY` in `wp-config.php`. Constants override saved values.

= Does media offloading remove local files? =

Not by default. Keeping local copies supports image editing, thumbnail regeneration, backups, and recovery from provider outages. Local removal is an advanced opt-in choice.

= Can existing Media Library files be offloaded? =

Yes. Use the resumable background media job on the Media storage tab. The same controls can verify remote objects or restore missing local copies.

= How can a private MinIO endpoint be used? =

Private network destinations are blocked by default to prevent unsafe requests. A site owner can explicitly allow a trusted internal endpoint with the `vyompress_boost_allow_private_s3_endpoint` filter. HTTPS is required outside a local WordPress environment.

= How can another plugin bypass the page cache? =

Define the standard `DONOTCACHEPAGE` constant as true, send a private or no-store Cache-Control response, set a session cookie, or use the `vyompress_boost_is_cacheable_request` filter.

== External services ==

VyomPress Boost does not contact an external service until an administrator enables and configures the related integration.

= Cloudflare =

When enabled, the plugin sends the configured zone ID and authenticated cache-purge requests to Cloudflare after relevant WordPress changes or an explicit administrator action. If an administrator uses edge-rule management, it also reads and changes the zone cache ruleset to create, update, or remove only the rule identified as belonging to VyomPress Boost. Cache-rule management requires Zone Cache Rules Edit permission in addition to Cache Purge permission. Cloudflare processes these requests under its terms and privacy policy.

* Service: https://www.cloudflare.com/
* Terms: https://www.cloudflare.com/website-terms/
* Privacy policy: https://www.cloudflare.com/privacypolicy/

= Administrator-selected S3-compatible storage =

When enabled, the plugin sends WordPress media files, filenames, MIME types, cache metadata, and authenticated object requests directly from the WordPress server to the endpoint configured by the administrator. VyomPress does not receive those files or credentials. The selected provider's own terms and privacy policy apply.

Common compatible providers:

* AWS S3 — https://aws.amazon.com/s3/ — Terms: https://aws.amazon.com/service-terms/ — Privacy: https://aws.amazon.com/privacy/
* Cloudflare R2 — https://www.cloudflare.com/developer-platform/products/r2/ — Terms: https://www.cloudflare.com/website-terms/ — Privacy: https://www.cloudflare.com/privacypolicy/
* DigitalOcean Spaces — https://www.digitalocean.com/products/spaces — Terms: https://www.digitalocean.com/legal/terms-of-service-agreement — Privacy: https://www.digitalocean.com/legal/privacy-policy
* Wasabi — https://wasabi.com/cloud-object-storage — Terms: https://wasabi.com/legal/terms-of-use — Privacy: https://wasabi.com/legal/privacy-policy
* Backblaze B2 — https://www.backblaze.com/cloud-storage — Terms: https://www.backblaze.com/company/terms.html — Privacy: https://www.backblaze.com/company/privacy.html

For another compatible service or a self-hosted MinIO deployment, review the endpoint operator's own terms and privacy policy before connecting it.

== Changelog ==

= 0.3.0 =

* Added a throttled sitemap cache preloader with automatic post-purge warming.
* Added safe Cloudflare full-page edge Cache Rule installation, updating, and removal without replacing unrelated rules.
* Added resumable existing-media offload, remote verification, and local restore jobs.
* Added a local operational activity history and background-job status summaries.

= 0.2.0 =

* Added Cloudflare automatic and manual cache purging with scoped API tokens.
* Added provider-neutral S3 media offloading, URL rewriting, remote deletion, connection testing, and safe local-copy defaults.
* Added query normalization, configurable exclusions, mobile variants, and independent browser-cache lifetime.
* Rebuilt the settings interface around guided tabs, status cards, safer credential handling, and Site Health diagnostics.
* Added privacy disclosures and external-service documentation for WordPress.org review.

= 0.1.0 =

* Initial public release with anonymous page caching and focused WordPress optimizations.

== Upgrade Notice ==

= 0.3.0 =

Adds optional cache preloading, Cloudflare edge-rule management, and resumable existing-media operations. Existing integrations remain unchanged until enabled or started by an administrator.

= 0.2.0 =

Adds optional Cloudflare and S3-compatible integrations. Both remain disabled until explicitly configured.
