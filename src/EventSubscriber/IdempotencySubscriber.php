<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\EventSubscriber;

use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;
use Hanane\IdempotencyGuardBundle\Model\ProcessingContext;
use Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface;
use Psr\Log\LoggerInterface;
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

    public function __construct(
        private readonly IdempotencyStorageInterface $storage,
        private readonly RequestFingerprint $fingerprint,
        private readonly StorageKeyFactory $keys,
        private readonly LockFactory $locks,
        private readonly int $defaultTtl = 86400,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->contexts = new \WeakMap();
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

        $values = $request->headers->all('Idempotency-Key');
        if ($values === []) {
            $this->replaceController($event, $this->error('IDEMPOTENCY_KEY_REQUIRED', 400));

            return;
        }

        // An opaque visible ASCII value, 1-255 bytes; commas reject coalesced duplicate headers.
        if (count($values) !== 1 || preg_match('/\A[\x21-\x2B\x2D-\x7E]{1,255}\z/D', $values[0]) !== 1) {
            $this->replaceController($event, $this->error('IDEMPOTENCY_KEY_INVALID', 400));

            return;
        }

        // Raw-body fingerprints do not reliably describe parsed form fields or uploaded files.
        if ($request->files->count() > 0 || $request->request->count() > 0
            || str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'multipart/')) {
            $this->replaceController($event, $this->error('IDEMPOTENCY_BODY_UNSUPPORTED', 400));

            return;
        }

        $lock = null;
        $acquired = false;
        try {
            $key = $this->keys->create($request, $values[0]);
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
        } catch (\Throwable $exception) {
            $this->logger?->error('Idempotency lookup or locking failed.', ['exception' => $exception]);
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
        $context = $this->contexts[$request] ?? null;
        if ($context === null) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store');

        if (!$response->isSuccessful() || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return;
        }

        try {
            $this->storage->save(IdempotencyRecord::capture(
                $context->key,
                $context->fingerprint,
                $response,
                $context->ttl,
            ));
        } catch (\Throwable $exception) {
            $this->logger?->error('Idempotency response persistence failed.', ['exception' => $exception]);
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
            $this->logger?->error('Idempotency lock release failed.', ['exception' => $exception]);
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
