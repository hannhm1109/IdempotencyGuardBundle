<?php

declare(strict_types=1);

use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Hanane\IdempotencyGuardBundle\EventSubscriber\IdempotencySubscriber;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $directory, $role] = $argv;
$controller = new class($directory, $role) {
    public function __construct(private readonly string $directory, private readonly string $role)
    {
    }

    #[Idempotent]
    public function __invoke(): JsonResponse
    {
        file_put_contents($this->directory.'/executions', "executed\n", FILE_APPEND | LOCK_EX);
        if ($this->role === 'first') {
            file_put_contents($this->directory.'/entered', 'ready');
            $deadline = microtime(true) + 15;
            while (!is_file($this->directory.'/continue')) {
                clearstatcache();
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('The test did not release the first request.');
                }
                usleep(10000);
            }
        }

        return new JsonResponse(['order' => 123], 201, ['Location' => '/orders/123']);
    }
};

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new IdempotencySubscriber(
    new CacheIdempotencyStorage(new FilesystemAdapter('concurrency', 0, $directory.'/cache')),
    new RequestFingerprint(),
    new StorageKeyFactory(),
    new LockFactory(new FlockStore($directory.'/locks')),
));
$kernel = new HttpKernel($dispatcher, new ControllerResolver(), new RequestStack());
$request = Request::create('/orders', 'POST', server: [
    'HTTP_IDEMPOTENCY_KEY' => 'simultaneous-order',
    'CONTENT_TYPE' => 'application/json',
], content: '{"amount":100}');
$request->attributes->set('_controller', $controller);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
