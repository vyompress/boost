# VyomPress Boost

Safe page caching and focused performance optimizations for WordPress.

[![CI](https://github.com/vyompress/boost/actions/workflows/ci.yml/badge.svg)](https://github.com/vyompress/boost/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL_v2_or_later-blue.svg)](LICENSE)

## Features

- Conservative anonymous HTML page caching.
- Automatic invalidation after content, comment, theme, plugin, and settings changes.
- Bypasses logged-in users, previews, query strings, REST requests, and common ecommerce sessions.
- Optional browser-cache headers for validated cache hits.
- Optional emoji, embed, and Heartbeat optimizations.
- WordPress Site Health integration.
- No account, telemetry, advertisements, or external service dependency.

## Requirements

- WordPress 6.4 or newer.
- PHP 8.1 or newer.

## Development

```bash
composer install
composer lint
composer test
```

Run the live WordPress smoke test:

```bash
docker compose -f tests/integration/compose.yaml up --abort-on-container-exit --exit-code-from smoke
docker compose -f tests/integration/compose.yaml down --volumes --remove-orphans
```

Create the WordPress.org-ready ZIP on Windows or PowerShell 7:

```powershell
./tools/build-release.ps1
```

The package is written to `dist/vyompress-boost-0.1.0.zip`.

## Security

Please report vulnerabilities privately through [GitHub Security Advisories](https://github.com/vyompress/boost/security/advisories/new). Do not open a public issue for a suspected vulnerability.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
