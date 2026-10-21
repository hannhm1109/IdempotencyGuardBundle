# Contributing

Keep changes focused on opt-in HTTP idempotency and response replay. Do not add unrelated platform features.

```bash
composer install
composer check
composer audit
```

`composer check` validates metadata strictly, lints PHP, runs PHPStan level 8 on `src/`, executes PHPUnit, and checks the runnable quickstart. New correctness/failure paths need meaningful regression coverage. Preserve numeric precision, request privacy, principal scope, and lock ownership through persistence. Authorization and response-listener ordering are part of the public contract.

The suite includes a real FrameworkBundle container, a mock clock for TTL boundaries, and separate PHP processes for single-host concurrency. Generated files use `var/` and a dedicated temporary test directory; do not commit dependencies, locks, or caches. The Composer lock is deliberately omitted for this reusable library so CI resolves its declared constraints.

CI covers PHP 8.1-8.5 with Symfony 6.4, Windows PHP 8.1, and the lowest dependency versions allowed by current Composer security advisories. Dependency advisories can make the safe lower-bound set change over time. Keep action revisions pinned and review dependency-update PRs.

Changes to the fingerprint format, principal scope, storage key, or record schema require an explicit upgrade plan. Do not silently invalidate records or permit old/new workers to use different locks for the same operation. See [the Phase 1 upgrade discussion](docs/phase-2.md#upgrading-from-the-phase-1-checkpoint).

Report sensitive issues using [SECURITY.md](SECURITY.md). For releases, follow [the release procedure](docs/releasing.md).
