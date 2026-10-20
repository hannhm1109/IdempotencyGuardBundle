<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Integration;

use Hanane\IdempotencyGuardBundle\EventSubscriber\IdempotencySubscriber;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface;
use Hanane\IdempotencyGuardBundle\Tests\Fixtures\OrderController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
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

    protected function setUp(): void
    {
        $this->controller = new OrderController();
        $this->pool = new ArrayAdapter();
        $this->tokens = new TokenStorage();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->dispatcher = new EventDispatcher();
        $this->installSubscriber(new CacheIdempotencyStorage($this->pool));
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

    private function installSubscriber(IdempotencyStorageInterface $storage): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new IdempotencySubscriber(
            $storage,
            new RequestFingerprint(),
            new StorageKeyFactory($this->tokens),
            $this->locks,
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
