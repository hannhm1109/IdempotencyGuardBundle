<?php

declare(strict_types=1);

use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Hanane\IdempotencyGuardBundle\EventSubscriber\IdempotencySubscriber;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__).'/vendor/autoload.php';

// An in-process demonstration, not a persistent order service or a web server.
// In a Symfony application, register the bundle; it wires these services for you.
$controller = new class {
    public int $executions = 0;

    #[Idempotent(ttl: 3600)]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['order' => ++$this->executions], 201, ['Location' => '/orders/1']);
    }
};

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new IdempotencySubscriber(
    new CacheIdempotencyStorage(new ArrayAdapter()),
    new RequestFingerprint(),
    new StorageKeyFactory(),
    new LockFactory(new FlockStore(sys_get_temp_dir().'/idempotency-guard-quickstart')),
));
$kernel = new HttpKernel($dispatcher, new ControllerResolver(), new RequestStack());
$statuses = [];
foreach ([
    'First request' => '{"amount":100,"currency":"MAD"}',
    'Equivalent JSON retry' => '{"currency":"MAD", "amount":100}',
    'Changed payload' => '{"amount":200,"currency":"MAD"}',
] as $label => $body) {
    $request = Request::create('/orders', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'demo-order-123',
    ], content: $body);
    $request->attributes->set('_controller', $controller);
    $response = $kernel->handle($request);
    $statuses[] = $response->getStatusCode();
    echo $label.': '.$response->getStatusCode().' '.$response->getContent().PHP_EOL;
}
echo 'Controller executions: '.$controller->executions.PHP_EOL;
if ($statuses !== [201, 201, 409] || $controller->executions !== 1) {
    exit(1);
}
