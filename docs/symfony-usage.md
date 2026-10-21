# Symfony usage

Enable the bundle as described in the [README](../README.md). Keep the controller's actual business work in an application service. For example, given an existing `App\Application\Orders` service returning an order with an `id`:

```php
<?php

namespace App\Controller;

use App\Application\Orders;
use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OrderController
{
    public function __construct(private readonly Orders $orders)
    {
    }

    #[Route('/api/orders', name: 'create_order', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[Idempotent(ttl: 3600)]
    public function create(Request $request): JsonResponse
    {
        $order = $this->orders->create($request->toArray());

        return new JsonResponse(['id' => $order->id], 201, [
            'Location' => '/api/orders/'.$order->id,
        ]);
    }
}
```

`Orders` is your application's service, not a service supplied by this bundle. Perform input validation and database invariants there. Put authorization needed on every retry in firewall rules/`#[IsGranted]` before the guard; a check placed only inside `create()` is skipped on replay. Use a voter with the appropriate subject for object-level authorization. Controller argument resolvers and earlier listeners still run and must not perform business effects.

## Try retries

These examples use a POSIX shell. Replace the authentication mechanism with the one configured in your application:

```bash
curl -i http://localhost:8000/api/orders \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Idempotency-Key: order-123' \
  -H 'Content-Type: application/json' \
  --data '{"amount":100,"currency":"MAD"}'

# Same authenticated user and key; JSON key order does not matter.
curl -i http://localhost:8000/api/orders \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Idempotency-Key: order-123' \
  -H 'Content-Type: application/json' \
  --data '{"currency":"MAD", "amount":100}'
```

The first call executes the controller and records its ordinary 2xx response. The retry replays that response after authorization without calling `Orders::create()` again. Reusing the key with `amount: 200` returns `409 IDEMPOTENCY_KEY_CONFLICT`. Missing the key returns 400. A competing in-flight request receives `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` with `Retry-After: 1`; retry the same operation key after the indicated delay.

## Use a dedicated pool

Separate replay records from caches routinely cleared for deployments:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.idempotency:
                adapter: cache.adapter.filesystem

# config/packages/idempotency_guard.yaml
idempotency_guard:
    default_ttl: 86400
    header_name: Idempotency-Key
    storage: cache.idempotency
```

A dedicated pool still needs a stable storage location and an eviction/clear policy consistent with the retry window. All workers must share it and the same lock directory; a named filesystem pool alone does not guarantee persistence across changing release directories.

The default lock service uses `%kernel.cache_dir%/idempotency_guard/locks`. If deployment changes that directory, configure a stable local directory in application services:

```yaml
# config/services.yaml
services:
    idempotency_guard.lock_store:
        class: Symfony\Component\Lock\Store\FlockStore
        arguments: ['%kernel.project_dir%/var/idempotency-locks']
```

That path must stay the same across all workers/releases and support local `flock`. Supply the application's stable shared data-directory path instead if `kernel.project_dir` changes between releases. This example remains single-host; it does not supply distributed coordination.

After enabling the bundle, run `php bin/console lint:container` in your application and exercise the endpoint with your real authentication, authorization, response listeners, cache, and deployment paths. Do not use secret-returning, multipart, streaming, file, partial, or pre-encoded responses for these endpoints.
