# Changelog

## 0.1.0 — 2026-10-21

### Initial behavior

- Method-level `#[Idempotent]` with a positive optional TTL.
- Required, bounded idempotency header and a small Symfony configuration tree.
- Principal/origin-scoped SHA-256 keys and JSON-aware request fingerprints without floating-point rounding.
- PSR-6 storage abstraction with absolute expiration and a Symfony Cache default.
- Single-host Symfony FlockStore exclusion, retryable in-flight conflicts, and response replay for matching completed requests.
- Safe response-header allowlist, bounded buffered bodies, predictable errors, and generic infrastructure logs.
- Unit, HttpKernel, FrameworkBundle, TTL, authorization, failure, and separate-process concurrency tests.

### Release review fixes

- Discard a stored snapshot under its lock when a later response listener throws.
- Report the package root through the bundle path API.
- Reject null request-header values as invalid client input; omit null response-header values from snapshots.

### Packaging and maintenance

- MIT license, complete Composer metadata, documentation index, usage/release/security guides, and runnable quickstart.
- Strict Composer validation, PHP lint, PHPStan level 8, dependency audit, and a pinned GitHub Actions matrix.
- Distribution rules exclude development tools, tests, dependencies, and generated files.

Phase 1 was a development checkpoint with a different key/fingerprint format. See [the coordinated upgrade requirements](docs/phase-2.md#upgrading-from-the-phase-1-checkpoint). Packagist registration is separate from the GitHub release.
