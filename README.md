# VyomPress Boost

VyomPress Boost is a production-focused WordPress performance plugin with conservative page caching, optional Cloudflare cache automation, and provider-neutral S3 media offloading.

## Highlights

- Safe anonymous page cache with configurable exclusions, normalized tracking parameters, mobile variants, and automatic invalidation.
- Throttled sitemap-driven cache preloading with background progress tracking.
- Optional Cloudflare purge integration using scoped API tokens and debounced background events.
- Safe management of one plugin-owned Cloudflare full-page edge Cache Rule.
- S3 Signature Version 4 media uploads compatible with AWS S3, Cloudflare R2, Spaces, Wasabi, Backblaze B2 S3, and compatible MinIO endpoints.
- Resumable jobs for existing-media offload, remote verification, and local restoration.
- Browser-native lazy loading, LCP image priority, speculative navigation, resource hints, and guarded JavaScript delivery controls.
- Bounded database maintenance with retention controls and optional weekly scheduling.
- Expiring S3 Signature Version 4 private media URLs and local WebP/AVIF sub-size generation.
- Per-page cache/optimization controls, WP-CLI automation, and professional Site Health diagnostics.
- Credentials may be kept in `wp-config.php`; secret values are never returned to the settings-page browser.
- Responsive, accessible administration UI with connection tests and Site Health checks.
- No telemetry, advertising, remote code, or VyomPress account.

## Development

Requirements: PHP 8.1+, Composer 2, and Docker for the live WordPress smoke test.

```powershell
composer install
composer validate --strict
composer lint
composer test
docker compose -f tests/integration/compose.yaml up --abort-on-container-exit --exit-code-from smoke
./tools/build-release.ps1
```

The release archive is written to `dist/` and contains only WordPress runtime files.

## Credential constants

The following optional `wp-config.php` constants override saved credentials:

```php
define( 'VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN', '...' );
define( 'VYOMPRESS_BOOST_S3_ACCESS_KEY', '...' );
define( 'VYOMPRESS_BOOST_S3_SECRET_KEY', '...' );
```

## WP-CLI

```bash
wp vyompress-boost status
wp vyompress-boost cache purge
wp vyompress-boost cache preload
wp vyompress-boost media offload
wp vyompress-boost media verify
wp vyompress-boost media restore
wp vyompress-boost media regenerate
wp vyompress-boost database
```

## Security and privacy

Cloudflare and S3 integrations are disabled by default. The plugin sends requests only to services explicitly enabled and configured by a site administrator. See `readme.txt` for complete external-service disclosures and `SECURITY.md` for vulnerability reporting.

## License

GPL-2.0-or-later.
