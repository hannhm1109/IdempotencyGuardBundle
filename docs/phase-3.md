# Phase 3: review and release preparation

## Outcome

The bundle is prepared for an initial 0.1.0 release. The package name remains `hanane/idempotency-guard-bundle`, the license is MIT, and the declared Symfony line remains 6.4 on PHP 8.1+. No database adapter, distributed locking backend, authentication system, or unrelated product feature was added. A Git tag, GitHub release, and Packagist registration are separate publication actions; this phase prepares the repository and documents those steps.

## Review findings and fixes

- The bundle now reports the package root with `getPath()`. Its code lives under `src/`, while Composer metadata, documentation, and license live one level above it. This follows the [Symfony reusable bundle layout](https://symfony.com/doc/6.4/bundles/best_practices.html).
- A later `kernel.response` listener could throw after a successful snapshot was saved. The request context now remembers that save, and `kernel.exception` discards the snapshot while still holding the operation lock. A regression test proves the failed HTTP result is not replayed as a cached success. A reported deletion failure is logged without sensitive details and remains an uncertain storage failure.
- Symfony header bags allow null values. Such request keys now produce the expected 400 instead of reaching a regular-expression TypeError; null allowlisted response values are omitted from snapshots. Both paths have regression tests.
- PHPStan level 8, with Symfony metadata support, checks `src/` without a baseline or ignored errors. The findings above were fixed rather than suppressed.

The security review checked principal/origin key scope, authorization before replay, hash inputs, temporary JSON handling, persisted bodies/headers, public errors, exception/log cleanup, cache failures, expiry, and lock ownership. It is a review of this code and its documented contract, not an independent audit or an exactly-once guarantee. [SECURITY.md](../SECURITY.md) records the boundaries and reporting process.

## Backend/Symfony decisions to retain

Routing and argument resolution establish the actual controller; `#[IsGranted]` must run before replay. The guard remains a late `kernel.controller_arguments` subscriber. Response snapshotting happens at `kernel.response`, and exception/finish-request events release ownership. Controller Throwable cleanup also covers standalone kernels that do not catch PHP Errors from the controller itself. Custom listeners still need to respect the documented priorities.

A cache lookup is not a concurrency primitive. Acquiring a Symfony lock before lookup and retaining it through response persistence protects the complete critical section. FlockStore is a practical non-expiring single-host choice, while the PSR-6 storage interface keeps persistence replaceable. Neither coordinates the business commit atomically with response persistence.

Request identity remains conservative: recursively ordered JSON object members, unchanged array order/types, exact numeric tokens, selected semantic headers, and a SHA-256 digest. This avoids precision loss and accidental equivalence while accepting insignificant JSON formatting. Response replay reconstructs only supported body/status/header data rather than serializing an entire Symfony Response.

## Checks and evidence

The development stack is Windows/PHP 8.1.25 with current allowed Symfony 6.4 components. The final local suite contains **131 tests and 300 assertions**.

```bash
composer check
composer audit
composer check-platform-reqs
composer dump-autoload --optimize --strict-psr
```

`composer check` runs strict Composer validation, PHP lint, PHPStan level 8, PHPUnit, and the runnable quickstart. The quickstart must produce 201, 201, and 409 with one controller execution. The bundle has no application `bin/console`; real container boot/DI integration is checked by its FrameworkBundle tests. The consuming application should run its own `lint:container` and endpoint tests.

An isolated checkout also exercises the lowest declared dependency constraints, including Symfony 6.4.0. Those old dependencies have known advisories and are used only as compatibility evidence; current dependencies in the working tree are audited separately. CI uses current Composer's security-aware resolution for its lowest-dependency job and audits the result.

The pinned GitHub Actions workflow covers PHP 8.1-8.5 with Symfony 6.4, Windows/PHP 8.1, and a security-aware lowest-dependency job. It grants read-only repository permissions and does not persist checkout credentials. Inspect the [CI results for the exact commit](https://github.com/hannhm1109/IdempotencyGuardBundle/actions/workflows/ci.yml) before tagging; adding a workflow alone is not evidence that every matrix job passed. Symfony 7/8 compatibility is intentionally not advertised.

Packaging checks build a Composer ZIP, inspect runtime/docs/license inclusion and development/generated-file exclusion, then install the extracted package into a clean Symfony consumer with `--no-dev`. That consumer boots the bundle through a real FrameworkBundle container, executes and replays a request once, verifies its installed bundle root, and confirms PHPUnit is absent. `.gitattributes` also excludes development files from Git distribution archives.

## Files changed

- `src/IdempotencyGuardBundle.php`, `src/Model/ProcessingContext.php`, `src/EventSubscriber/IdempotencySubscriber.php`, `src/Request/RequestValidator.php`, and `src/Model/IdempotencyRecord.php`: review corrections described above.
- `tests/Integration/IdempotencyTest.php` and `tests/Unit/IdempotencyRecordTest.php`: failure/header regressions.
- `composer.json`, `.gitattributes`, `phpstan.neon.dist`, `tools/lint.php`, `.github/workflows/ci.yml`, and `.github/dependabot.yml`: metadata, validation, static checks, archive rules, and ongoing CI/dependency maintenance.
- `LICENSE`, `README.md`, `CHANGELOG.md`, `SECURITY.md`, and `CONTRIBUTING.md`: package contract, license, history, safety boundaries, and development instructions.
- `examples/quickstart.php`, `docs/index.md`, `docs/symfony-usage.md`, `docs/releasing.md`, and this guide: runnable example, application integration, release preparation, and explanation.

## Recommended publication text

Package name: **hanane/idempotency-guard-bundle**.

GitHub description:

> Symfony bundle for idempotency keys, request fingerprinting, single-host concurrency protection, and safe HTTP response replay.

Packagist description:

> Idempotency keys and safe response replay for selected Symfony HTTP operations.

Initial version: **0.1.0**, tagged as `v0.1.0` after the final CI/release decision. Composer infers its version from Git; metadata does not hard-code one. See [the concrete publication steps](releasing.md).

## A 30-second interview explanation

> I built a Symfony bundle that lets an API action opt into idempotency with an attribute. It validates the operation key, fingerprints the request without retaining its raw payload, and uses a Symfony lock around lookup, controller execution, and response persistence. Matching retries replay a completed response; different input conflicts. I tested authorization ordering, JSON precision, expiry, failures, and concurrent PHP processes. The key trade-off is that cache persistence and the business commit are separate, so it reduces duplicate execution without promising exactly-once behavior across crashes.

## Known limits and realistic improvements

The current contract is single-host, buffered responses up to 1 MiB, trusted cache storage, and stable globally unique user identities. Anonymous private responses, tenant-local identities without additional scope design, multi-host coordination, forms/files/streams, arbitrary late response transformations, and strict transaction-level deduplication are outside it. Cache clearing/eviction, hidden read failures, and the business-commit/save gap can allow re-execution. JSON number spelling and query/content-type differences are deliberately conservative. The Phase 1 key format needs the coordinated transition documented in the Phase 2 guide.

Useful later work, only when backed by a consuming application's need:

1. Add Symfony 7/8 compatibility after running an explicit supported runtime/framework matrix.
2. Offer an optional durable adapter with a design that coordinates the record and business transaction; simply replacing the cache with a database does not create atomicity.
3. Document and test one distributed lock deployment with lease renewal, execution bounds, and shared storage if multi-host operation is required.
4. Add a narrowly scoped integration example for an actual application/provider that keeps its own uniqueness/idempotency safeguards.

All three planned phases end here. Publication remains the maintainer's next release action, not an additional development phase.
