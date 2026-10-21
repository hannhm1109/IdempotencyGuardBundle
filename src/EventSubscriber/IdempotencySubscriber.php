<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\EventSubscriber;

use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Hanane\IdempotencyGuardBundle\Exception\InvalidIdempotencyRequest;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;
use Hanane\IdempotencyGuardBundle\Model\ProcessingContext;
use Hanane\IdempotencyGuardBundle\Request\RequestValidator;
use Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class IdempotencySubscriber implements EventSubscriberInterface
{
    /** @var \WeakMap<Request, ProcessingContext> */
    private \WeakMap $contexts;
    /** @var \WeakMap<Request, bool> */
    private \WeakMap $protectedRequests;

    public function __construct(
        private readonly IdempotencyStorageInterface $storage,
        private readonly RequestFingerprint $fingerprint,
        private readonly StorageKeyFactory $keys,
        private readonly LockFactory $locks,
        private readonly int $defaultTtl = 86400,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $headerName = 'Idempotency-Key',
        private readonly RequestValidator $requests = new RequestValidator(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->contexts = new \WeakMap();
        $this->protectedRequests = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Symfony's IsGranted listener runs at +20, before any replay.
            KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', -256],
            KernelEvents::RESPONSE => ['onResponse', -1024],
            KernelEvents::EXCEPTION => ['onException', 1024],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', -1024],
        ];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $attribute = $event->getAttributes(Idempotent::class)[0] ?? null;
        if ($attribute === null) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $this->protectedRequests[$request] = true;

        $lock = null;
        $acquired = false;
        try {
            $clientKey = $this->requests->key($request, $this->headerName);
            $key = $this->keys->create($request, $clientKey);
            $fingerprint = $this->fingerprint->generate($request);
            // The default FlockStore has no lease expiry during controller execution.
            $lock = $this->locks->createLock($key);
            if (!($acquired = $lock->acquire())) {
                $response = $this->error('IDEMPOTENCY_REQUEST_IN_PROGRESS', 409);
                $response->headers->set('Retry-After', '1');
                $this->replaceController($event, $response);

                return;
            }

            $record = $this->storage->find($key);
            if ($record !== null) {
                $response = hash_equals($record->fingerprint, $fingerprint)
                    ? $record->toResponse()
                    : $this->error('IDEMPOTENCY_KEY_CONFLICT', 409);
                $this->replaceController($event, $response);

                return;
            }

            $this->contexts[$request] = new ProcessingContext(
                $key,
                $fingerprint,
                $attribute->ttl ?? $this->defaultTtl,
                $lock,
            );
            $controller = $event->getController();
            $attributes = $event->getAttributes();
            // Also clean up TypeError/Error when a kernel does not catch all Throwables.
            $event->setController(function (...$arguments) use ($controller, $request): mixed {
                try {
                    return $controller(...$arguments);
                } catch (\Throwable $exception) {
                    $this->release($request);
                    throw $exception;
                }
            }, $attributes);
        } catch (InvalidIdempotencyRequest $exception) {
            $this->replaceController($event, $this->error($exception->errorCode, $exception->statusCode));
        } catch (\Throwable $exception) {
            $this->logFailure('Idempotency lookup or locking failed.', $exception);
            $this->replaceController($event, $this->error('IDEMPOTENCY_UNAVAILABLE', 500));
        } finally {
            if ($acquired && $lock !== null && !isset($this->contexts[$request])) {
                $this->releaseLock($lock);
            }
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (isset($this->protectedRequests[$request])) {
            // Apply after ordinary response listeners on originals, replays, and errors.
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
        }
        $context = $this->contexts[$request] ?? null;
        if ($context === null) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful() || $response->getStatusCode() === 206 || $response->headers->has('Content-Range')
            || $response->headers->has('Content-Encoding')
            || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return;
        }

        try {
            $this->storage->save(IdempotencyRecord::capture(
                $context->key,
                $context->fingerprint,
                $response,
                $context->ttl,
                $this->clock->now()->getTimestamp(),
            ));
        } catch (\Throwable $exception) {
            $this->logFailure('Idempotency response persistence failed.', $exception);
            $event->setResponse($this->error('IDEMPOTENCY_UNAVAILABLE', 500));
        }
        // Keep ownership until finish_request, after the rest of the response listeners.
    }

    public function onException(ExceptionEvent $event): void
    {
        // An exception converted into a 2xx by another listener must never be recorded.
        $this->release($event->getRequest());
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        $this->release($event->getRequest());
        unset($this->protectedRequests[$event->getRequest()]);
    }

    private function release(Request $request): void
    {
        $context = $this->contexts[$request] ?? null;
        if ($context !== null) {
            unset($this->contexts[$request]);
            $this->releaseLock($context->lock);
        }
    }

    private function releaseLock(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable $exception) {
            $this->logFailure('Idempotency lock release failed.', $exception);
        }
    }

    private function logFailure(string $message, \Throwable $exception): void
    {
        try {
            // Backend exception messages/traces can contain keys, credentials, or payloads.
            $this->logger?->error($message, ['exception_class' => $exception::class]);
        } catch (\Throwable) {
            // A broken logger must not bypass fail-closed behavior or lock cleanup.
        }
    }

    private function replaceController(ControllerArgumentsEvent $event, Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $event->setController(static fn (): Response => $response);
        $event->setArguments([]);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status, ['Cache-Control' => 'private, no-store']);
    }
}
