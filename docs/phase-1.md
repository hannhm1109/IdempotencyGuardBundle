# Phase 1: architecture and core idempotency

This guide describes the Phase 1 checkpoint. For current normalization, configuration, safety behavior, and the Phase 1 upgrade boundary, read [the Phase 2 guide](phase-2.md).

## The smallest convincing V1

One attribute, one request header, a deterministic request fingerprint, a completed response record, a PSR-6 storage adapter, and a Symfony lock are enough to demonstrate the core behavior. A real HttpKernel test must prove that two matching requests execute the controller once. No database adapter, processing-state machine, dashboard, queue, or retry engine is needed.

The architecture is technically sound for bounded HTTP request/response operations. Its guarantee depends on the continued availability of the record and coordination through the same lock backend. It does not promise exactly-once business execution across arbitrary process failures.

## Symfony extension points

`IdempotencyGuardBundle` is a conventional bundle. Symfony discovers `IdempotencyGuardExtension`, which processes the small configuration tree and registers services explicitly. The application supplies `cache.app`; no Doctrine dependency or database schema is required. `IdempotencyStorageInterface` is the one replaceable storage boundary.

`#[Idempotent]` is a non-repeatable method attribute with one optional positive integer TTL. Restricting it to methods makes opt-in explicit and keeps class-wide inheritance rules out of the first version. The subscriber uses Symfony's controller metadata API instead of maintaining its own reflection system.

The event choices are deliberate:

| Event | Responsibility | Reason |
| --- | --- | --- |
| `kernel.controller_arguments`, priority -256 | Inspect the opt-in, validate the request, lock, look up the record, substitute a replay/error controller when appropriate | Runs after routing, argument resolution, and Symfony's `IsGrantedAttributeListener` at priority +20 |
| `kernel.response`, priority -1024 | Snapshot an ordinary successful response | The controller/view layer has produced the response and ordinary response listeners have run |
| `kernel.exception`, priority +1024 | Drop the pending context and release its lock | An exception must not turn into a completed idempotency record |
| `kernel.finish_request`, priority -1024 | Release any remaining lock | Cleanup occurs before HttpKernel returns, without depending on `terminate()` being called |

Why not `kernel.request`? It runs before the final controller and its attributes are available. Why not substitute at `kernel.controller`? That would change the metadata before `#[IsGranted]` is evaluated, potentially bypassing authorization. Late `kernel.controller_arguments` interception avoids that problem. Replacing the callable also clears its arguments, because they were resolved for the original controller.

Custom listeners can run at any priority. An application must keep authorization earlier than the guard and verify any response transformations that run later than its response snapshot. Controller argument resolvers still run on replay and must not create orders or perform other business effects.

## The flow

1. Ignore unprotected actions, safe methods, and subrequests.
2. Require one valid `Idempotency-Key`; reject unsupported parsed forms/uploads.
3. Derive a hashed storage key for the origin, base path, principal, and client key.
4. Hash the request's identity and body.
5. Acquire the Symfony lock without waiting indefinitely.
6. Look up storage **after** acquiring the lock.
7. If a matching record exists, substitute a controller returning its response; if its fingerprint differs, substitute a 409 response. Release the lookup lock.
8. For a new key, keep a request-local processing context and execute the original controller.
9. At `kernel.response`, store an ordinary 2xx response while still owning the lock.
10. At request cleanup, release the lock. A later retry acquires it and checks the stored result.

Request-local state lives in a `WeakMap<Request, ProcessingContext>`. This keeps lock objects out of application request attributes and avoids retaining completed requests in a long-lived service. A separate persistent `PROCESSING` record would duplicate the lock's responsibility here, so storage contains completed records only.

## Fingerprints and key scope answer different questions

The storage key asks: **whose operation key is this?** It hashes the client key with the request origin/base path and, when Symfony Security has an authenticated user, the user's class and identifier plus the token's firewall name when available. No credential is used as the identity. Applications must use globally unique identities within that scope, and tenant-local identities need additional tenant scoping before deployment. Anonymous requests share one scope and must not expose private responses.

The fingerprint asks: **is this the same request?** In Phase 1 it includes:

- A format version, HTTP method, and scheme/host/port.
- Base path plus request path.
- The exact query string, including its ordering and duplicates.
- Content type and the exact request body.

Length-prefixed fields prevent ambiguous concatenation. SHA-256 produces a fixed-length digest, which is all that is stored from the request. Authorization headers, cookies, and volatile request headers are excluded. The body is read without consuming it for the controller.

Different paths within the same key scope cause a conflict rather than silently allocating another operation. Different users get separate keys. The host is part of the scope, so trusted hosts/proxies should already be correctly configured by the consuming application.

Raw-byte comparison is intentionally the Phase 1 baseline. Logically equal JSON with reordered keys is not yet equal. Phase 2 will add recursive object-key normalization while preserving array order and distinguishing objects, arrays, scalars, and invalid JSON. Query normalization and numeric normalization require separate, explicit policies; they must not silently collapse meaningful differences.

## Storage and response policy

`IdempotencyRecord` is an immutable completed snapshot containing the hashed key, fingerprint, status, body, header allowlist, creation time, and expiration. `CacheIdempotencyStorage` uses a PSR-6 pool and absolute expiration. Expired records are absent; no cleanup command is needed. Failed save/delete return values become exceptions rather than false successes. The PSR-6 contract does not guarantee backend read failures will be distinguishable from misses.

`cache.app` is a convenient default because a Symfony application already has it. Its lifecycle matters: eviction or cache clearing removes protection. A dedicated pool with a deliberate deployment/retention policy can replace it. The config's `storage` value identifies a PSR-6 pool; a custom storage implementation replaces the storage interface alias instead.

Store only ordinary 2xx results. Do not retain redirects, 4xx, 5xx, exceptions, binary files, or streams in this phase. Keeping failure policy narrow avoids freezing transient failures or incorrectly retaining request-specific validation/authentication outcomes. A later policy for deterministic business errors would require careful semantics rather than automatically storing every status.

Replay restores body, status, `Content-Type`, and `Location`. `Set-Cookie`, authentication headers, arbitrary application headers, dates, lengths, and transport metadata are not saved. Symfony can generate fresh headers on the replay. The snapshot is a response reconstruction rather than a serialization of the entire Response object, so response internals and cookie objects are not persisted.

Both original and replayed protected responses are marked `private, no-store` for HTTP intermediaries. That does not disable the bundle's deliberate internal response storage.

## Locking and recovery

Checking the cache and only then locking is unsafe: two processes can both observe a miss. The lock must enclose lookup, controller execution, and persistence. Locks and stored records use the same derived operation key.

The default Symfony `FlockStore` is a practical single-host backend. File locks are released by the operating system on process exit and have no lease expiry while a slow controller is still running. The bundle fails promptly with `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` and `Retry-After: 1` if another request owns the lock. A client can then retry and receive the completed result. This avoids tying up PHP workers with unbounded waiting.

Flock is not a multi-host lock and is not suitable for every network filesystem. Replacing it with an expiring distributed store is not enough by itself: a lease can expire while the operation continues. Lease refresh, execution bounds, backend consistency, and shared cache scope need a deliberate strategy. Phase 2 expands locking tests; Phase 1 supplies the basic exclusion and cleanup.

The database commit/cache-save gap is unavoidable in this design. If a controller commits an order and the process dies before saving the replay record, the next request sees no completed response. The same uncertainty exists if persistence fails after controller execution. Database uniqueness constraints, transactional idempotency records, or an external provider's own keys may still be necessary for strict financial/business invariants. This bundle does not replace them.

## Security and technical risks

- Replays skip the controller body. Every access check needed on a retry must run before replay, for example through firewall access control or `#[IsGranted]`.
- Principal scoping protects users from sharing records accidentally. It does not solve tenant-local identities or private anonymous responses automatically.
- The stored body may contain personal data. Do not protect endpoints returning secrets or access tokens. Cache access, retention, encryption at rest, and data handling remain application decisions.
- Hashing avoids retaining the raw request. Predictable low-entropy inputs can still be guessed; hashing is not encryption or anonymization.
- A successful cache save is separate from the business transaction. Failure and eviction can permit re-execution.
- PSR-6 adapters can hide read failures as misses. Fail-closed handling only applies to failures that are reported.
- Response listeners can modify the response after the snapshot. Verify custom priorities and avoid request-specific body transformations on protected endpoints.
- Request and response bodies are buffered. Application/proxy limits should bound memory and storage costs.
- Only Symfony 6.4 is declared in this phase. Broader version claims need actual compatibility runs before publication.

## Files introduced

| Area | Files and purpose |
| --- | --- |
| Package | `composer.json`, `.gitignore`, `.gitattributes`, `phpunit.xml.dist` |
| Bundle/DI | `src/IdempotencyGuardBundle.php`, `src/DependencyInjection/Configuration.php`, `src/DependencyInjection/IdempotencyGuardExtension.php` |
| Opt-in | `src/Attribute/Idempotent.php` |
| Request identity | `src/Fingerprint/RequestFingerprint.php`, `src/Key/StorageKeyFactory.php` |
| State | `src/Model/IdempotencyRecord.php`, `src/Model/ProcessingContext.php` |
| Persistence | `src/Storage/IdempotencyStorageInterface.php`, `src/Storage/CacheIdempotencyStorage.php` |
| HTTP lifecycle | `src/EventSubscriber/IdempotencySubscriber.php` |
| Verification | `tests/Fixtures/OrderController.php`, `tests/Integration/IdempotencyTest.php`, `tests/Integration/BundleBootTest.php`, `tests/Unit/RequestFingerprintTest.php` |
| Explanation | `README.md`, `docs/phase-1.md` |

## How to test

Run `composer install`, then `composer test`. To run just HTTP behavior, use `vendor/bin/phpunit tests/Integration/IdempotencyTest.php` (on Windows, `php vendor/phpunit/phpunit/phpunit tests/Integration/IdempotencyTest.php`).

The integration suite uses a real HttpKernel and asserts that the second matching request preserves status/body and leaves the execution counter at one. It also verifies the small header allowlist, the missing-key policy, body/path conflicts, user separation, authorization on replay, held-lock behavior, failure cleanup, unprotected actions, and subrequests. The bundle boot test compiles a real FrameworkBundle container and uses the registered subscriber with its default filesystem lock/cache setup.

Unit tests check deterministic fingerprinting and sensitivity to request changes while excluding credentials. Full JSON/validation/TTL/parallel-process/failure matrices belong to Phase 2.

## What to understand for interviews

Be ready to explain these decisions in your own words:

1. **Idempotency versus replay:** idempotency is about repeated effects; a stored HTTP response is one mechanism, with a limited lifetime and failure window.
2. **Symfony lifecycle:** routing, controller resolution, argument resolution, security attributes, execution, response processing, and cleanup have different extension points. Listener ordering is part of correctness.
3. **Concurrency:** a cache is storage, not mutual exclusion. The read-check-execute-write sequence needs a lock around the whole critical section.
4. **Request identity:** the client key identifies an attempted operation; the fingerprint prevents that key from being reused for different input; principal scope prevents unrelated users from sharing it.
5. **HTTP correctness:** safe methods are not opted in, missing keys are rejected predictably, status/body are preserved, and cookies/transport headers must not be blindly replayed.
6. **Storage trade-offs:** PSR-6 keeps the package small and replaceable, but expiry, eviction, read-failure semantics, and non-transactional persistence constrain the guarantee.
7. **Crash recovery:** a lock prevents overlapping execution while held. It cannot remember a committed operation after a crash if no durable record was saved.

This project exercises Symfony metadata, DI, events, HTTP semantics, concurrency, and failure handling in a small, explainable scope. Each design choice has an observable test or a documented limit.

## Phase boundary

Phase 1 ends with a working bundle and basic first-request/replay behavior plus the minimum guards needed to avoid unsafe core behavior. Some conflict, expiration, validation, header, and locking plumbing is already present because these cannot safely be ignored in the core path; they are not considered Phase 2 complete.

Phase 2 covers logical JSON normalization and the stronger safety/edge-case tests for validation, expiration, concurrency, failed responses, configuration, headers, and privacy. Phase 3 covers review, static checks, final documentation/metadata, license, and publication. Neither phase starts automatically.
