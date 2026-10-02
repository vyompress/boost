# Contributing

## Development workflow

1. Fork the repository and create a focused branch.
2. Install development dependencies with `composer install`.
3. Add or update tests for behavioral changes.
4. Run `composer lint` and `composer test`.
5. Open a pull request describing the change, risk, and verification performed.

Keep features independently configurable, preserve conservative cache-bypass behavior, and never introduce telemetry or external requests without explicit user consent and documentation.

Security vulnerabilities must be reported through the private process in [SECURITY.md](SECURITY.md), not through public issues.
