<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Integration;

use Hanane\IdempotencyGuardBundle\EventSubscriber\IdempotencySubscriber;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;
use Hanane\IdempotencyGuardBundle\Request\RequestValidator;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface;
use Hanane\IdempotencyGuardBundle\Tests\Fixtures\OrderController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener;

final class IdempotencyTest extends TestCase
{
    private OrderController $controller;
    private ArrayAdapter $pool;
    private TokenStorage $tokens;
    private LockFactory $locks;
    private EventDispatcher $dispatcher;
    private HttpKernel $kernel;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->controller = new OrderController();
        $this->pool = new ArrayAdapter();
        $this->tokens = new TokenStorage();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->dispatcher = new EventDispatcher();
        $this->clock = new MockClock('2099-01-01T00:00:00Z');
        $this->installSubscriber(new CacheIdempotencyStorage($this->pool, $this->clock));
    }

    public function testFirstRequestIsStoredAndDuplicateDoesNotExecuteController(): void
    {
        $first = $this->kernel->handle($this->request());
        $second = $this->kernel->handle($this->request());

        self::assertSame(201, $first->getStatusCode());
        self::assertSame($first->getStatusCode(), $second->getStatusCode());
        self::assertSame($first->getContent(), $second->getContent());
        self::assertSame('application/json', $second->headers->get('Content-Type'));
        self::assertSame('/orders/1', $second->headers->get('Location'));
        self::assertSame(1, $this->controller->executions);

        $key = (new StorageKeyFactory($this->tokens))->create($this->request(), 'order-123');
        $record = (new CacheIdempotencyStorage($this->pool))->find($key);
        self::assertNotNull($record);
        self::assertSame(3600, $record->expiresAt - $record->createdAt);
        self::assertSame('{"order":1}', $record->responseBody);
        self::assertSame(['content-type', 'location'], array_keys($record->responseHeaders));
    }

    public function testCookiesAndUnapprovedHeadersAreNotReplayed(): void
    {
        $first = $this->kernel->handle($this->request());
        $second = $this->kernel->handle($this->request());

        self::assertCount(1, $first->headers->getCookies());
        self::assertCount(0, $second->headers->getCookies());
        self::assertFalse($second->headers->has('X-Request-Secret'));
        self::assertTrue($second->headers->hasCacheControlDirective('no-store'));
    }

    public function testDifferentPayloadOrPathCannotReplayAnUnrelatedResponse(): void
    {
        $this->kernel->handle($this->request());
        $payloadConflict = $this->kernel->handle($this->request(body: '{"amount":200}'));
        $pathConflict = $this->kernel->handle($this->request(path: '/refunds'));

        self::assertSame(409, $payloadConflict->getStatusCode());
        self::assertSame('{"error":"IDEMPOTENCY_KEY_CONFLICT"}', $payloadConflict->getContent());
        self::assertSame(409, $pathConflict->getStatusCode());
        self::assertSame(1, $this->controller->executions);
    }

    public function testMissingKeyDoesNotExecuteController(): void
    {
        $response = $this->kernel->handle($this->request(key: null));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"IDEMPOTENCY_KEY_REQUIRED"}', $response->getContent());
        self::assertSame(0, $this->controller->executions);
    }

    public function testUnprotectedControllerAndSafeMethodsExecuteNormally(): void
    {
        $this->kernel->handle($this->request(action: 'unprotected', key: null));
        $this->kernel->handle($this->request(action: 'unprotected', key: null));
        $request = $this->request(key: null);
        $request->setMethod('GET');
        $this->kernel->handle($request);

        self::assertSame(3, $this->controller->executions);
    }

    public function testSubrequestsAreNotIntercepted(): void
    {
        $response = $this->kernel->handle($this->request(key: null), HttpKernelInterface::SUB_REQUEST);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->controller->executions);
    }

    public function testFailedResponseIsNotStoredAndItsLockIsReleased(): void
    {
        self::assertSame(500, $this->kernel->handle($this->request(action: 'failure'))->getStatusCode());
        self::assertSame(500, $this->kernel->handle($this->request(action: 'failure'))->getStatusCode());
        self::assertSame(2, $this->controller->executions);
    }

    public function testControllerExceptionDoesNotStoreAResponseOrLeakItsLock(): void
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->kernel->handle($this->request(action: 'exception'), catch: false);
                self::fail('Expected the controller exception.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Controller failure', $exception->getMessage());
            }
        }

        self::assertSame(2, $this->controller->executions);
    }

    public function testAnAlreadyHeldLockPreventsControllerExecution(): void
    {
        $request = $this->request();
        $key = (new StorageKeyFactory($this->tokens))->create($request, 'order-123');
        $competitor = $this->locks->createLock($key);
        self::assertTrue($competitor->acquire());

        try {
            $response = $this->kernel->handle($request);
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('{"error":"IDEMPOTENCY_REQUEST_IN_PROGRESS"}', $response->getContent());
            self::assertSame('1', $response->headers->get('Retry-After'));
            self::assertSame(0, $this->controller->executions);
        } finally {
            $competitor->release();
        }

        self::assertSame(201, $this->kernel->handle($this->request())->getStatusCode());
    }

    public function testSameClientKeyIsIsolatedBetweenAuthenticatedUsers(): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $alice = $this->kernel->handle($this->request());
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('bob', null), 'main'));
        $bob = $this->kernel->handle($this->request());
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $aliceReplay = $this->kernel->handle($this->request());

        self::assertSame('{"order":1}', $alice->getContent());
        self::assertSame('{"order":2}', $bob->getContent());
        self::assertSame($alice->getContent(), $aliceReplay->getContent());
        self::assertSame(2, $this->controller->executions);
    }

    public function testIsGrantedRunsAgainBeforeReplayingAStoredResponse(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::exactly(2))->method('isGranted')->with('CREATE_ORDER', null)
            ->willReturnOnConsecutiveCalls(true, false);
        $this->dispatcher->addSubscriber(new IsGrantedAttributeListener($authorization));

        self::assertSame(201, $this->kernel->handle($this->request(action: 'secured'))->getStatusCode());

        try {
            $this->kernel->handle($this->request(action: 'secured'), catch: false);
            self::fail('Authorization must deny the replay.');
        } catch (AccessDeniedException) {
            self::assertSame(1, $this->controller->executions);
        }
    }

    public function testStorageFailureStopsExecutionWithoutExposingDetails(): void
    {
        $storage = $this->createMock(IdempotencyStorageInterface::class);
        $storage->method('find')->willThrowException(new \RuntimeException('private-infrastructure-details'));
        $this->installSubscriber($storage);

        $response = $this->kernel->handle($this->request());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"error":"IDEMPOTENCY_UNAVAILABLE"}', $response->getContent());
        self::assertSame(0, $this->controller->executions);
    }

    public function testReorderedJsonReplaysWithoutConsumingTheControllerBody(): void
    {
        $first = $this->request(body: '{"amount":100,"currency":"MAD","items":[{"a":1,"b":2}]}');
        $retry = $this->request(body: '{ "items": [{"b":2,"a":1}], "currency":"MAD", "amount":100 }');
        self::assertSame(201, $this->kernel->handle($first)->getStatusCode());
        self::assertSame('{"order":1}', $this->kernel->handle($retry)->getContent());
        self::assertSame('{"amount":100,"currency":"MAD","items":[{"a":1,"b":2}]}', $first->getContent());
        self::assertSame(1, $this->controller->executions);
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysNeverExecuteTheController(string|array|null $key): void
    {
        $request = $this->request();
        $request->headers->set('Idempotency-Key', $key);
        $response = $this->kernel->handle($request);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"IDEMPOTENCY_KEY_INVALID"}', $response->getContent());
        self::assertSame(0, $this->controller->executions);
    }

    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'null header bag value' => [null];
        yield 'oversized' => [str_repeat('a', 256)];
        yield 'space' => ['has space'];
        yield 'non-ASCII' => ['clé'];
        yield 'newline' => ["key\r\nsecret"];
        yield 'NUL byte' => ["key\0secret"];
        yield 'coalesced duplicate' => ['first,second'];
        yield 'separate duplicates' => [['first', 'second']];
    }

    public function testMaximumLengthOpaqueKeyIsAccepted(): void
    {
        self::assertSame(201, $this->kernel->handle($this->request(key: str_repeat('a', 255)))->getStatusCode());
    }

    public function testMalformedAndDuplicateJsonDoNotReserveAKey(): void
    {
        foreach (['{"amount":', '{"amount":1,"amount":2}'] as $body) {
            $response = $this->kernel->handle($this->request(body: $body));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('{"error":"IDEMPOTENCY_JSON_INVALID"}', $response->getContent());
        }
        self::assertSame(0, $this->controller->executions);
        self::assertSame(201, $this->kernel->handle($this->request())->getStatusCode());
    }

    public function testExpiredRecordExecutesAgainAndDoesNotExtendOnReplay(): void
    {
        $this->kernel->handle($this->request());
        $this->clock->sleep(3599);
        self::assertSame('{"order":1}', $this->kernel->handle($this->request())->getContent());
        $this->clock->sleep(1);
        self::assertSame('{"order":2}', $this->kernel->handle($this->request())->getContent());
        self::assertSame(2, $this->controller->executions);
    }

    public function testConfiguredTtlAppliesWhenAttributeHasNoOverride(): void
    {
        $this->installSubscriber(new CacheIdempotencyStorage($this->pool, $this->clock), defaultTtl: 30);
        $this->kernel->handle($this->request(action: 'defaultTtl'));
        $this->clock->sleep(30);
        self::assertSame('{"order":2}', $this->kernel->handle($this->request(action: 'defaultTtl'))->getContent());
    }

    public function testCustomHeaderNameIsCaseInsensitiveAndReplacesTheDefaultHeader(): void
    {
        $this->installSubscriber(new CacheIdempotencyStorage($this->pool, $this->clock), headerName: 'X-Operation-Key');
        self::assertSame(400, $this->kernel->handle($this->request())->getStatusCode());
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $request = $this->request(key: null);
            $request->headers->set('x-operation-key', 'operation-123');
            self::assertSame(201, $this->kernel->handle($request)->getStatusCode());
        }
        self::assertSame(1, $this->controller->executions);
    }

    #[DataProvider('mutationMethods')]
    public function testAllSupportedMutationMethodsReplay(string $method): void
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $request = $this->request();
            $request->setMethod($method);
            self::assertSame(201, $this->kernel->handle($request)->getStatusCode());
        }
        self::assertSame(1, $this->controller->executions);
    }

    public static function mutationMethods(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('responseStatuses')]
    public function testOnlyFullSuccessfulResponsesAreStored(int $status, bool $stored): void
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $request = $this->request(action: 'respond');
            $request->attributes->set('test_response', new Response('result', $status));
            self::assertSame($status, $this->kernel->handle($request)->getStatusCode());
        }
        self::assertSame($stored ? 1 : 2, $this->controller->executions);
    }

    public static function responseStatuses(): iterable
    {
        foreach ([200 => true, 201 => true, 204 => true, 206 => false, 302 => false, 400 => false, 409 => false, 422 => false, 500 => false, 503 => false] as $status => $stored) {
            yield (string) $status => [$status, $stored];
        }
    }

    public function testStreamingFileAndEncodedResponsesAreNotSnapshotted(): void
    {
        foreach ([new StreamedResponse(static function (): void {}), new BinaryFileResponse(__FILE__), new Response('encoded', 200, ['Content-Encoding' => 'gzip']), new Response('partial', 200, ['Content-Range' => 'bytes 0-6/100'])] as $index => $response) {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $request = $this->request(action: 'respond', key: 'unsupported-'.$index);
                $request->attributes->set('test_response', clone $response);
                $this->kernel->handle($request);
            }
        }
        self::assertSame(8, $this->controller->executions);
    }

    public function testRequestSizeAndUnsupportedRepresentationsFailBeforeExecution(): void
    {
        $oversized = $this->request(body: str_repeat('x', RequestValidator::MAX_BODY_BYTES + 1));
        self::assertSame(413, $this->kernel->handle($oversized)->getStatusCode());
        foreach (['application/x-www-form-urlencoded', 'multipart/form-data; boundary=test'] as $type) {
            $request = $this->request();
            $request->headers->set('Content-Type', $type);
            self::assertSame(400, $this->kernel->handle($request)->getStatusCode());
        }
        $encoded = $this->request();
        $encoded->headers->set('Content-Encoding', 'gzip');
        self::assertSame(400, $this->kernel->handle($encoded)->getStatusCode());
        $partial = $this->request();
        $partial->headers->set('Range', 'bytes=0-5');
        self::assertSame(400, $this->kernel->handle($partial)->getStatusCode());
        self::assertSame(0, $this->controller->executions);
    }

    public function testOversizedResponseFailsWithoutCreatingARecord(): void
    {
        $request = $this->request(action: 'respond');
        $request->attributes->set('test_response', new Response(str_repeat('x', IdempotencyRecord::MAX_BODY_BYTES + 1)));
        self::assertSame(500, $this->kernel->handle($request)->getStatusCode());
        $key = (new StorageKeyFactory())->create($request, 'order-123');
        self::assertNull((new CacheIdempotencyStorage($this->pool, $this->clock))->find($key));
        self::assertSame(1, $this->controller->executions);
    }

    public function testSaveFailureReturnsAnErrorAndReleasesTheLock(): void
    {
        $storage = $this->createMock(IdempotencyStorageInterface::class);
        $storage->method('find')->willReturn(null);
        $storage->method('save')->willThrowException(new \RuntimeException('private details'));
        $this->installSubscriber($storage);
        $response = $this->kernel->handle($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"error":"IDEMPOTENCY_UNAVAILABLE"}', $response->getContent());
        self::assertSame(1, $this->controller->executions);
        $lock = $this->locks->createLock((new StorageKeyFactory())->create($this->request(), 'order-123'));
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    public function testLockInfrastructureFailureFailsClosedAndLogsOnlyTheExceptionClass(): void
    {
        $store = $this->createMock(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new LockStorageException('private-token=secret'));
        $this->locks = new LockFactory($store);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Idempotency lookup or locking failed.', self::callback(static fn (array $context): bool => array_keys($context) === ['exception_class'] && !str_contains(serialize($context), 'private-token')));
        $this->installSubscriber(new CacheIdempotencyStorage($this->pool, $this->clock), logger: $logger);
        self::assertSame('{"error":"IDEMPOTENCY_UNAVAILABLE"}', $this->kernel->handle($this->request())->getContent());
        self::assertSame(0, $this->controller->executions);
    }

    public function testBrokenLoggingCannotBypassFailureHandling(): void
    {
        $storage = $this->createMock(IdempotencyStorageInterface::class);
        $storage->method('find')->willThrowException(new \RuntimeException('storage failure'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('logger failure'));
        $this->installSubscriber($storage, logger: $logger);
        self::assertSame(500, $this->kernel->handle($this->request())->getStatusCode());
        self::assertSame(0, $this->controller->executions);
    }

    public function testTypeErrorDoesNotLeakALockEvenWhenTheKernelDoesNotCatchErrors(): void
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->kernel->handle($this->request(action: 'typeError'));
                self::fail('Expected TypeError.');
            } catch (\TypeError $exception) {
                self::assertSame('Controller type error', $exception->getMessage());
            }
        }
        self::assertSame(2, $this->controller->executions);
    }

    public function testExceptionConvertedIntoSuccessIsNotStored(): void
    {
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event): void {
            $event->allowCustomResponseCode();
            $event->setResponse(new Response('recovered', 200));
        });
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertSame(200, $this->kernel->handle($this->request(action: 'exception'))->getStatusCode());
        }
        self::assertSame(2, $this->controller->executions);
    }

    public function testExceptionAfterSnapshotDiscardsTheRecordBeforeReleasingTheLock(): void
    {
        $this->dispatcher->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event): void {
            if ($event->getResponse()->getStatusCode() === 201) {
                throw new \RuntimeException('A later response listener failed.');
            }
        }, -2048);
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event): void {
            $event->setResponse(new Response('Failed response processing', 500));
        });
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertSame(500, $this->kernel->handle($this->request())->getStatusCode());
        }
        self::assertSame(2, $this->controller->executions);
        $key = (new StorageKeyFactory())->create($this->request(), 'order-123');
        self::assertNull((new CacheIdempotencyStorage($this->pool, $this->clock))->find($key));
    }

    public function testResponseListenersRunAgainAndCannotMakeTheSnapshotPublic(): void
    {
        $seen = 0;
        $this->dispatcher->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event) use (&$seen): void {
            $event->getResponse()->headers->set('X-Current-Request', (string) ++$seen);
            $event->getResponse()->headers->set('Cache-Control', 'public, max-age=3600');
        });
        $this->kernel->handle($this->request());
        $replay = $this->kernel->handle($this->request());
        self::assertSame('2', $replay->headers->get('X-Current-Request'));
        self::assertTrue($replay->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($replay->headers->hasCacheControlDirective('public'));
    }

    public function testSnapshotsContainNoRequestCredentialsOrRawRequestPayload(): void
    {
        $request = $this->request(body: '{"private":"request-secret"}');
        $request->headers->set('Authorization', 'Bearer access-secret');
        $request->headers->set('Cookie', 'session=cookie-secret');
        $this->kernel->handle($request);
        $key = (new StorageKeyFactory())->create($request, 'order-123');
        $record = (new CacheIdempotencyStorage($this->pool, $this->clock))->find($key);
        self::assertNotNull($record);
        $snapshot = serialize($record);
        foreach (['request-secret', 'access-secret', 'cookie-secret', 'order-123', 'must-not-be-stored', 'private-cookie'] as $secret) {
            self::assertStringNotContainsString($secret, $snapshot);
        }
    }

    private function installSubscriber(IdempotencyStorageInterface $storage, int $defaultTtl = 86400, ?LoggerInterface $logger = null, string $headerName = 'Idempotency-Key'): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new IdempotencySubscriber(
            $storage,
            new RequestFingerprint(),
            new StorageKeyFactory($this->tokens),
            $this->locks,
            $defaultTtl,
            $logger,
            $headerName,
            clock: $this->clock,
        ));
        $this->kernel = new HttpKernel($this->dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
    }

    private function request(
        string $action = 'create',
        ?string $key = 'order-123',
        string $body = '{"amount":100}',
        string $path = '/orders',
    ): Request {
        $request = Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->attributes->set('_controller', [$this->controller, $action]);
        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
    }
}
