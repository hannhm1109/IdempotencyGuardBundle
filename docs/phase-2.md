# Phase 2: safety and edge cases

## What changed

The core flow remains one opt-in attribute, one operation key, a lock around lookup/execution/persistence, and a completed response snapshot. Phase 2 adds logical JSON comparison, bounded request validation, precise expiration tests, replay safeguards, predictable failures, configuration checks, and a real multi-process concurrency test. It does not add a database adapter, distributed locking, background processing, or a retry framework.

## JSON identity without precision loss

The fingerprint is a SHA-256 hash of length-prefixed request fields. Method, origin/base path/path, exact query string, and content type identify the operation. Representation headers (`Accept`, `Accept-Language`, `Accept-Encoding`) and conditional mutation headers (`If-Match`, `If-None-Match`, `If-Modified-Since`, `If-Unmodified-Since`) are also included because changing them can change a result or its preconditions. Authorization/cookie headers and unrelated volatile headers are excluded.

JSON processing applies to `application/json` and application media types ending in `+json`, including media types with parameters. The original full content-type value remains part of the fingerprint. An entirely empty body is valid; whitespace-only JSON is invalid.

Native `json_decode` first validates syntax, UTF-8, escapes, and a maximum nesting depth of 64. The decoded PHP values are discarded. A small structural walk over the validated JSON tokens then sorts object members recursively, preserves array order and container types, and normalizes equivalent string escapes. Duplicate object keys, including escaped aliases such as `"a"` and `"\u0061"`, are rejected rather than silently choosing one value.

Why not decode, sort PHP arrays, and re-encode? Empty objects and arrays must stay distinct, big integers must not turn into strings, and decimals must not be rounded through floats. Keeping numeric tokens exact avoids those collisions. It deliberately means `100`, `100.0`, and `1e2` conflict, even when an application treats them as the same number. Unicode escape equivalence is supported; Unicode composition normalization is not. This is a conservative comparison policy, not an implementation of an external JSON canonicalization standard.

The PHP manual documents [JSON decoding behavior](https://www.php.net/manual/en/function.json-decode.php) and [encoding's floating-point precision settings](https://www.php.net/manual/en/function.json-encode.php). These are the reason the bundle preserves number text. Native validation handles JSON grammar; the token walk only supplies the ordering and identity policy needed for idempotency.

Non-JSON bodies and query strings stay byte-exact. Request content remains available unchanged to the controller. Normalized content is temporary; the stored record contains only its digest.

## Validation and configuration

The public configuration has three fields:

```yaml
idempotency_guard:
    default_ttl: 86400
    header_name: Idempotency-Key
    storage: cache.app
```

`default_ttl` must be a positive integer. A positive attribute TTL overrides it. `header_name` must be an HTTP field-name token; credential/cookie header names are rejected. Header lookup is case-insensitive. `storage` names a PSR-6 pool service. Applications can still replace the storage interface alias with a custom adapter.

One non-empty key containing 1-255 visible ASCII bytes is required. Commas are excluded because proxies can combine repeated headers with commas. Separate duplicate headers are also rejected. The bundle does not trim or case-fold operation keys.

Parsed fields, URL-encoded forms, multipart/files, encoded request bodies, and Range/If-Range requests are rejected before controller execution. They require different representation handling and remain outside this version. Request bodies above 1 MiB return 413. This limit is checked on the actual content rather than trusting Content-Length; the web server/proxy must still prevent oversized input from reaching PHP in the first place.

Errors expose only a stable code and status:

| Code | Status |
| --- | --- |
| `IDEMPOTENCY_KEY_REQUIRED` | 400 |
| `IDEMPOTENCY_KEY_INVALID` | 400 |
| `IDEMPOTENCY_BODY_UNSUPPORTED` | 400 |
| `IDEMPOTENCY_JSON_INVALID` | 400 |
| `IDEMPOTENCY_RANGE_UNSUPPORTED` | 400 |
| `IDEMPOTENCY_BODY_TOO_LARGE` | 413 |
| `IDEMPOTENCY_KEY_CONFLICT` | 409 |
| `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 409, with `Retry-After: 1` |
| `IDEMPOTENCY_UNAVAILABLE` | 500 |

## Expiration and storage

The subscriber and cache adapter share an injected PSR clock backed by Symfony NativeClock. Capture time and expiry checks therefore use the same time source. Symfony MockClock makes expiry tests deterministic, including the exact boundary: `expiresAt <= now` is expired. A replay does not save again or extend the original lifetime. Cache items receive absolute expiry; no cleanup job is introduced.

The adapter treats a wrong record type or a record stored under another key as an error, rather than a new operation. Failed `save()` or `deleteItem()` return values become failures. Lookup/lock failures reported to the bundle prevent controller execution; a failed save after execution produces an uncertain 500 result without claiming a completed response.

Storage keys now use a full 64-character SHA-256 digest. Phase 1's `ig_` prefix made its keys 67 characters long, exceeding the portable key length every PSR-6 pool must support. The bundle name and format version are included inside the hashed data instead. See the [PSR-6 key and error contracts](https://www.php-fig.org/psr/psr-6/). Principal/origin scoping remains unchanged in meaning.

PSR-6 permits pools to hide backend errors as misses and to expire values early. The adapter cannot discover an outage that a backend reports as an ordinary miss. A cache is therefore a practical default, with a weaker durability contract than a transactional idempotency table. The interface remains available for applications needing stronger storage semantics.

## Replay, failure cleanup, and privacy

Only ordinary full buffered 2xx responses are recorded. Redirects, 4xx/5xx, exception-derived results, streams/files, partial responses, and already encoded responses are excluded. An exception converted into a 2xx by an error listener is still not a completed record. Unsupported response types return normally without a snapshot and can execute again on retry; use this bundle only on endpoints returning supported responses. Compression should happen after the snapshot, for example in the web server.

The header allowlist remains `Content-Type` and `Location`. It is applied when capturing and when rebuilding a response, including records supplied by a custom adapter. Cookies, credentials, hop-by-hop fields, lengths, arbitrary application headers, and dynamic transport metadata are not stored. Current Symfony response listeners can add fresh headers on replay. Response bodies are stored as returned; applications must keep secrets/access tokens out of protected endpoint responses.

The snapshot body limit is 1 MiB. An oversized response returns `IDEMPOTENCY_UNAVAILABLE`, as does a reported persistence failure. The controller may already have committed a business effect; a later retry may repeat that effect. Keep responses bounded and preserve database/provider safeguards where needed.

Originals, replays, and bundle errors receive `Cache-Control: private, no-store` after ordinary response listeners. Custom listeners changing body/status/cache policy must run before priority -1024. Arbitrary transformations after the snapshot are outside the replay contract. Authorization still belongs in firewall rules or `#[IsGranted]`, before priority -256 on `kernel.controller_arguments`, rather than only in controller code that a replay skips.

A small wrapper around the original callable releases its lock on any controller Throwable, including TypeError when a standalone kernel is configured not to catch PHP Errors. Symfony exception/finish-request listeners handle the remaining normal cleanup paths. Generic infrastructure logs contain only a fixed message and exception class. They omit backend messages and traces because those may contain keys, payloads, or credentials. A throwing logger does not bypass error handling or cleanup. Application and backend logs remain the application's responsibility.

## Concurrency test and deployment scope

The multi-process test uses two real PHP processes, a shared Symfony FilesystemAdapter, and Symfony FlockStore. A marker confirms request A has acquired the lock and entered the controller. While A is held there, request B receives a retryable 409 and the execution log remains at one entry. After A is allowed to finish and save, a third PHP process retries and receives A's stored response, still with one execution. File barriers coordinate the test; arbitrary sleep timing does not determine which request wins. Symfony Process is a development-only dependency for this cross-platform test.

This verifies single-host process coordination, not distributed or crash-atomic behavior. All workers/releases need the same lock directory and pool. Flock on unsuitable network filesystems is outside the supported default. Expiring distributed stores need a lease/renewal strategy and are not introduced here. See [Symfony's locking guidance](https://symfony.com/doc/6.4/lock.html).

The business commit/cache-save gap, cache eviction/clearing, private anonymous responses, and tenant-local user identifiers remain explicit limits. Authenticated user identifiers and classes must be stable and unique within their firewall. No new tenant/authentication system is added.

## Upgrading from the Phase 1 checkpoint

The key format and fingerprint format have changed before publication. Phase 1 records are not migrated or silently treated as equivalent. Do not mix Phase 1 and Phase 2 workers: their derived lock/storage keys differ. If Phase 1 was used beyond local experimentation, stop new protected work and retries, drain in-flight requests, retain the old version for its outstanding retry window (at least the longest TTL after its last completed operation), then switch all workers together. Keep old operation keys from being retried against the new empty namespace. An active deployment needs an explicit migration plan before changing these formats; clearing the pool alone is not such a plan.

For a fresh development checkout, no migration is needed. Publication/versioned compatibility policy belongs to Phase 3.

## Files changed

| Area | Files |
| --- | --- |
| JSON/request validation | New `src/Fingerprint/JsonBodyNormalizer.php`, `src/Request/RequestValidator.php`, `src/Exception/InvalidIdempotencyRequest.php`; updated `src/Fingerprint/RequestFingerprint.php` |
| Lifecycle, key scope, replay, TTL | Updated `src/EventSubscriber/IdempotencySubscriber.php`, `src/Key/StorageKeyFactory.php`, `src/Model/IdempotencyRecord.php`, `src/Storage/CacheIdempotencyStorage.php` |
| Configuration/wiring | Updated both files in `src/DependencyInjection/`; `composer.json` adds PSR/Symfony Clock and development-only Symfony Process |
| HTTP/config/concurrency verification | Updated `tests/Integration/IdempotencyTest.php`, `tests/Integration/BundleBootTest.php`, `tests/Fixtures/OrderController.php`; new `tests/Integration/ConcurrencyTest.php`, `tests/Fixtures/concurrency_worker.php` |
| Unit verification | Expanded `tests/Unit/RequestFingerprintTest.php`; new storage, record, configuration, and storage-key tests in `tests/Unit/` |
| Documentation | Updated `README.md`, added a checkpoint note to `docs/phase-1.md`, and added this guide |

## How to test

```bash
composer install
composer test
composer validate --no-check-publish
```

Target a specific concern with `php vendor/phpunit/phpunit/phpunit tests/Integration/ConcurrencyTest.php` or `php vendor/phpunit/phpunit/phpunit tests/Unit/RequestFingerprintTest.php`. The suite tests supported mutation methods, request/response limits, successful status/body replay, unsafe headers, JSON equivalence/type/precision distinctions, malformed/duplicate JSON, key validation, TTL override/expiry, configuration wiring, principal scope, authorization, failure cleanup, safe logs, and separate-process locking. It runs on the project's PHP 8.1/Symfony 6.4 stack.

MockClock advances virtual time without blocking. Generated Symfony test containers/logs and concurrency fixtures live beneath a dedicated directory in the system temporary folder, avoiding OneDrive read-only directory flags on Windows. Concurrency fixture cleanup verifies the resolved target stays beneath that named test root. Runtime bundle defaults remain unchanged.

Composer validation still warns about the missing license, which was scheduled for Phase 3. No license or release is published by this phase.

## Interview points

1. Explain why normalization must not erase array order, types, duplicate-key ambiguity, or number precision.
2. Explain the difference between a client key's principal scope and a request's fingerprint; selected representation/precondition headers matter, credentials do not belong in the record.
3. Explain why lock acquisition precedes lookup and ownership lasts through persistence, and what the real process test proves.
4. Explain exact versus sliding TTL and why injecting time makes boundary tests reliable.
5. Explain why a failure before execution differs from a save failure after a business commit. A 500 can represent an uncertain outcome.
6. Explain why cookies/transport headers are not snapshots and why response listeners and authorization order matter.
7. Explain PSR-6 portability and its failure/eviction limits, as well as why cache/lock format changes require coordinated upgrades.

Phase 2 stops here. Code/security review, static analysis, release metadata, license, publication readiness, and release preparation remain Phase 3.
