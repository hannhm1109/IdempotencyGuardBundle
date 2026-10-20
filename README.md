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
Same key + same exact request       -> replays the stored response
Same key + a different request      -> 409 Conflict
Another request is using the key    -> 409 Conflict; retry later
```

**Status: Phase 1 complete.** This is the core implementation, targeting PHP 8.1+ and Symfony 6.4. It is not yet a published or production-ready release. JSON normalization, broader safety tests, and publication work are reserved for the next two phases. See [the Phase 1 design and interview guide](docs/phase-1.md).

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
    storage: cache.app # A PSR-6 cache pool service, not a custom storage adapter ID.
```

The attribute's `ttl` overrides `default_ttl`. To supply a completely different storage adapter, alias `Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface` to your implementation in application services.

## Request behavior

Protection applies only to main POST, PUT, PATCH, and DELETE requests whose controller method has `#[Idempotent]`. GET/HEAD and subrequests are left to normal Symfony handling. Use JSON or another raw-body representation; parsed forms and multipart uploads are rejected in this phase.

Send one `Idempotency-Key` header containing 1-255 visible ASCII characters, excluding commas. UUIDs are suitable. Generate a new key for each operation and keep it when retrying that operation.

| Situation | Result |
| --- | --- |
| Missing key | 400, `IDEMPOTENCY_KEY_REQUIRED` |
| Empty, duplicate, oversized, or invalid key | 400, `IDEMPOTENCY_KEY_INVALID` |
| Parsed form or multipart upload | 400, `IDEMPOTENCY_BODY_UNSUPPORTED` |
| Existing key with another fingerprint | 409, `IDEMPOTENCY_KEY_CONFLICT` |
| Key currently locked | 409, `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `Retry-After: 1` |
| Reported storage/lock failure | 500, `IDEMPOTENCY_UNAVAILABLE` |

Error bodies have the form `{"error":"IDEMPOTENCY_KEY_REQUIRED"}`.

Keys are scoped by origin, application base path, and the authenticated user's class and identifier (plus firewall name when the token exposes it). Within that scope, reusing a key on another path conflicts. No token, authorization header, or cookie is stored. Anonymous callers share a scope: protect private responses with Symfony authentication and authorization.

Phase 1 fingerprints use SHA-256 over method, origin, path, exact query string, content type, and exact body bytes. JSON whitespace or key-order changes currently cause a conflict; logical JSON normalization is planned for Phase 2.

## Replay and concurrency

Only ordinary 2xx responses are recorded. Redirects, 4xx/5xx responses, exceptions, streamed responses, and binary-file responses are not recorded. Do not use this phase to protect controllers that perform effects while returning streams or files.

Replay restores the status, body, `Content-Type`, and `Location`. It does not restore `Set-Cookie`, arbitrary headers, transport headers, or credentials. Normal Symfony response listeners still run and can add fresh headers. Protected responses use `Cache-Control: private, no-store` for downstream HTTP caches; the internal idempotency record still exists.

A Symfony Lock covers lookup, execution, and saving. The default `FlockStore` coordinates processes on one host using `%kernel.cache_dir%/idempotency_guard/locks`. It has no expiring lease to run out while the controller is working. Competitors fail promptly and can retry after the first request completes. Retrying then checks storage under the lock and replays the result. Locks are released during request cleanup or exception handling.

This default is for a single host with a filesystem that supports `flock`. A shared cache alone does not make the lock distributed. Multi-host locking and expiring lock leases need an explicit deployment design and are outside Phase 1.

## Security and limits

- Use firewall rules and/or Symfony `#[IsGranted]` for authorization. They run before replay. Authorization placed only inside the controller will not execute on a replay. Custom access listeners must run before the bundle's `kernel.controller_arguments` priority of `-256`.
- User identifiers must be stable and globally unique within their user class and firewall. Applications with tenant-local identifiers need tenant-aware scoping before using this on private tenant data.
- Request bodies are hashed, not retained. Response bodies **are retained** and can contain personal data. Use an access-controlled cache, appropriate retention, and endpoints whose responses contain no passwords, secrets, or access tokens. Hashes do not anonymize low-entropy data.
- Expiration, eviction, clearing `cache.app`, and deployments that replace the cache can make a key new again. Clearing the cache is therefore a correctness decision. TTL starts when the response is recorded.
- A business commit and cache save are not one transaction. If the process crashes between them, or response persistence fails after execution, a later retry may execute again. Keep database uniqueness rules and provider idempotency where business correctness requires them.
- Reported cache errors stop execution. PSR-6 pools may mask backend read failures as misses; this adapter cannot distinguish those misses from genuinely new keys. Durable failure detection is not guaranteed by PSR-6.
- Fingerprinting buffers the body, and storage buffers the response. Keep payloads bounded at the application/proxy level. Size policies, JSON edge cases, and fuller failure testing belong to Phase 2.
- Argument resolution and pre-controller listeners still run on replay. Keep them free of business side effects.

## Development

```bash
composer test
composer validate --no-check-publish
```

The test suite uses the real HttpKernel, a booted FrameworkBundle container, and focused unit tests. It verifies replay without a second controller execution, status/header preservation, conflict prevention, required keys, user isolation, `#[IsGranted]` ordering, basic lock contention, and failure cleanup. Phase 1 does not claim full parallel-process or deployment coverage.

Publication metadata, license, static analysis, compatibility expansion, and release preparation are scheduled for Phase 3.
