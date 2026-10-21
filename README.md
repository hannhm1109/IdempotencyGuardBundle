# IdempotencyGuardBundle

IdempotencyGuardBundle adds idempotency-key handling to selected Symfony HTTP operations so retries can reuse a completed response instead of executing a controller again.

```php
use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders', methods: ['POST'])]
#[Idempotent(ttl: 3600)]
public function createOrder(): JsonResponse
{
    // Create the order using your application's services.
    return new JsonResponse(['id' => 123], 201);
}
```

```text
First request                       -> executes and stores a successful response
Same key + same logical JSON        -> replays the stored response
Same key + a different request      -> 409 Conflict
Another request is using the key    -> 409 Conflict; retry later
```

**Status: Phase 2 complete.** Targets PHP 8.1+ and Symfony 6.4. Safety and edge-case behavior is implemented and tested; publication and final review remain for Phase 3. See [the Phase 2 guide](docs/phase-2.md) for decisions, changed files, test coverage, interview topics, and the upgrade boundary from Phase 1. The [Phase 1 guide](docs/phase-1.md) records the original architecture.

## Try the source

```bash
git clone https://github.com/hannhm1109/IdempotencyGuardBundle.git
cd IdempotencyGuardBundle
composer install
composer test
```

The Composer package name is `hanane/idempotency-guard-bundle`. Until publication, install the development branch in a Symfony application using a VCS repository:

```bash
composer config repositories.idempotency-guard vcs https://github.com/hannhm1109/IdempotencyGuardBundle.git
composer require hanane/idempotency-guard-bundle:dev-main
```

Enable the bundle in `config/bundles.php` if your tooling has not enabled it:

```php
return [
    // Your existing bundles...
    Hanane\IdempotencyGuardBundle\IdempotencyGuardBundle::class => ['all' => true],
];
```

Optional configuration in `config/packages/idempotency_guard.yaml`:

```yaml
idempotency_guard:
    default_ttl: 86400
    header_name: Idempotency-Key
    storage: cache.app # A PSR-6 cache pool service, not a custom storage adapter ID.
```

The attribute's `ttl` overrides `default_ttl`. To supply a completely different storage adapter, alias `Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface` to your implementation in application services.

## Request behavior

Protection applies only to main POST, PUT, PATCH, and DELETE requests whose controller method has `#[Idempotent]`. GET/HEAD and subrequests are left to normal Symfony handling. Use JSON or another raw-body representation. Parsed forms, URL-encoded forms, multipart uploads, encoded request bodies, and range requests are rejected before execution.

Send one `Idempotency-Key` header containing 1-255 visible ASCII characters, excluding commas. UUIDs are suitable. Generate a new key for each operation and keep it when retrying that operation.

| Situation | Result |
| --- | --- |
| Missing key | 400, `IDEMPOTENCY_KEY_REQUIRED` |
| Empty, duplicate, oversized, or invalid key | 400, `IDEMPOTENCY_KEY_INVALID` |
| Parsed form or multipart upload | 400, `IDEMPOTENCY_BODY_UNSUPPORTED` |
| Encoded request body | 400, `IDEMPOTENCY_BODY_UNSUPPORTED` |
| Malformed JSON, duplicate object keys, or excessive nesting | 400, `IDEMPOTENCY_JSON_INVALID` |
| Range/If-Range request | 400, `IDEMPOTENCY_RANGE_UNSUPPORTED` |
| Request body over 1 MiB | 413, `IDEMPOTENCY_BODY_TOO_LARGE` |
| Existing key with another fingerprint | 409, `IDEMPOTENCY_KEY_CONFLICT` |
| Key currently locked | 409, `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `Retry-After: 1` |
| Reported storage/lock failure | 500, `IDEMPOTENCY_UNAVAILABLE` |

Error bodies have the form `{"error":"IDEMPOTENCY_KEY_REQUIRED"}`.

Keys are scoped by origin, application base path, and the authenticated user's class and identifier (plus firewall name when the token exposes it). Within that scope, reusing a key on another path conflicts. No token, authorization header, or cookie is stored. Anonymous callers share a scope: protect private responses with Symfony authentication and authorization.

Fingerprints use SHA-256 over method, origin, path, exact query string, content type, selected representation/precondition headers, and body. JSON objects are recursively sorted; array order and value types remain significant. Whitespace and equivalent string escapes do not change the JSON fingerprint. `application/json` and `application/*+json` are recognized, with nesting bounded to the native decoder's depth of 64. An entirely empty body is allowed.

Number tokens stay exact: `100`, `100.0`, and `1e2` intentionally differ. This conservative policy avoids rounding distinct large integers or precise decimals through PHP floats. Non-JSON bodies, query strings, and content-type parameters remain byte-exact. Headers included in identity are `Accept`, `Accept-Language`, `Accept-Encoding`, `If-Match`, `If-None-Match`, `If-Modified-Since`, and `If-Unmodified-Since`; credentials and unrelated headers are excluded.

## Replay and concurrency

Only ordinary full 2xx responses are recorded. Redirects, 4xx/5xx responses, exceptions, partial responses (206 or `Content-Range`), encoded responses (`Content-Encoding`), streamed responses, and binary-file responses are not recorded. Use this bundle on controllers returning ordinary buffered responses; unsupported responses can execute again on retry. Keep compression outside the snapshot step, for example at the web server.

Snapshots are limited to 1 MiB of response body. A larger body or a reported save failure returns `500 IDEMPOTENCY_UNAVAILABLE` without a completed record. The controller has already run in that case; the response is uncertain and a retry may repeat its effects. Bound response sizes before performing sensitive operations.

Replay restores the status, body, `Content-Type`, and `Location`. It does not restore `Set-Cookie`, arbitrary headers, transport headers, or credentials. Normal Symfony response listeners still run and can add fresh headers. Protected responses use `Cache-Control: private, no-store` for downstream HTTP caches; the internal idempotency record still exists.

A Symfony Lock covers lookup, execution, and saving. The default `FlockStore` coordinates processes on one host using `%kernel.cache_dir%/idempotency_guard/locks`. It has no expiring lease to run out while the controller is working. Competitors fail promptly and can retry after the first request completes. Retrying then checks storage under the lock and replays the result. Locks are released during request cleanup or exception handling.

This default is for a single host with a filesystem that supports `flock`. All workers must use the same lock directory and cache pool, including during deployment. A shared cache alone does not make the lock distributed. Multi-host locking and expiring lock leases need an explicit deployment design and are outside this version.

## Security and limits

- Use firewall rules and/or Symfony `#[IsGranted]` for authorization. They run before replay. Authorization placed only inside the controller will not execute on a replay. Custom access listeners must run before the bundle's `kernel.controller_arguments` priority of `-256`.
- User identifiers must be stable and globally unique within their user class and firewall. Applications with tenant-local identifiers need tenant-aware scoping before using this on private tenant data.
- Request bodies are hashed, not retained. Response bodies **are retained** and can contain personal data. Use an access-controlled cache, appropriate retention, and endpoints whose responses contain no passwords, secrets, or access tokens. Hashes do not anonymize low-entropy data.
- Expiration, eviction, clearing `cache.app`, and deployments that replace the cache can make a key new again. Clearing the cache is therefore a correctness decision. TTL starts when the response is recorded.
- A business commit and cache save are not one transaction. If the process crashes between them, or response persistence fails after execution, a later retry may execute again. Keep database uniqueness rules and provider idempotency where business correctness requires them.
- Reported cache errors stop execution. PSR-6 pools may mask backend read failures as misses; this adapter cannot distinguish those misses from genuinely new keys. Durable failure detection is not guaranteed by PSR-6.
- Fingerprinting buffers the body, and storage buffers the response. Fixed 1 MiB body limits bound the bundle's work, but application/proxy limits are still needed before PHP reads the request. Response bodies remain sensitive even when request bodies are hashed.
- Bundle logs contain a generic message and exception class, without backend exception messages/traces or request keys/payloads. Other application/backend logging has its own privacy policy.
- Response listeners that change the body, status, or downstream cache policy must run before the bundle's `kernel.response` priority of `-1024`. Arbitrary later transformations are outside the replay contract.
- Argument resolution and pre-controller listeners still run on replay. Keep them free of business side effects.

## Development

```bash
composer test
composer validate --no-check-publish
```

The test suite uses the real HttpKernel, a booted FrameworkBundle container, focused unit tests, a mock clock, and separate PHP processes sharing a filesystem cache and Symfony FlockStore. It covers JSON equivalence/precision, key validation, conflicts, exact TTL expiration, configuration wiring, user separation, `#[IsGranted]` ordering, response policy, safe headers/logging, failure cleanup, and concurrent exclusion followed by replay. It does not claim distributed or crash-atomic execution.

Publication metadata, license, static analysis, compatibility expansion, and release preparation are scheduled for Phase 3.
