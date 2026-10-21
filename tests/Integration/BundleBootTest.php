<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Integration;

use Hanane\IdempotencyGuardBundle\IdempotencyGuardBundle;
use Hanane\IdempotencyGuardBundle\DependencyInjection\IdempotencyGuardExtension;
use Hanane\IdempotencyGuardBundle\EventSubscriber\IdempotencySubscriber;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use Hanane\IdempotencyGuardBundle\Tests\Fixtures\OrderController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\Kernel;

final class BundleBootTest extends TestCase
{
    public function testCustomConfigurationWiresTheCachePoolHeaderAndClock(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir().'/idempotency-guard-bundle-tests/configured');
        $container->register('cache.operations', ArrayAdapter::class);
        (new IdempotencyGuardExtension())->load([[
            'default_ttl' => 30,
            'header_name' => 'X-Operation-Key',
            'storage' => 'cache.operations',
        ]], $container);
        $container->register('idempotency_guard.clock', MockClock::class)->setArguments(['2099-01-01T00:00:00Z'])->setPublic(true);
        $container->getDefinition(IdempotencySubscriber::class)->setPublic(true);
        $container->getDefinition(CacheIdempotencyStorage::class)->setPublic(true);
        $container->compile();

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($container->get(IdempotencySubscriber::class));
        $kernel = new HttpKernel($dispatcher, new ControllerResolver(), new RequestStack());
        $controller = new OrderController();
        $request = Request::create('/orders', 'POST', server: ['HTTP_X_OPERATION_KEY' => 'configured-key', 'CONTENT_TYPE' => 'application/json']);
        $request->attributes->set('_controller', [$controller, 'defaultTtl']);
        self::assertSame(201, $kernel->handle($request)->getStatusCode());
        $record = $container->get(CacheIdempotencyStorage::class)->find((new StorageKeyFactory())->create($request, 'configured-key'));
        self::assertNotNull($record);
        self::assertSame(30, $record->expiresAt - $record->createdAt);
        self::assertSame(201, $kernel->handle(clone $request)->getStatusCode());
        self::assertSame(1, $controller->executions);
        $container->get('idempotency_guard.clock')->sleep(30);
        self::assertSame(201, $kernel->handle(clone $request)->getStatusCode());
        self::assertSame(2, $controller->executions);
    }

    public function testBundleBootsAndRegistersItsSubscriberInARealSymfonyContainer(): void
    {
        $kernel = new class('test', true) extends Kernel {
            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new IdempotencyGuardBundle();
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(static function (ContainerBuilder $container): void {
                    $container->loadFromExtension('framework', ['secret' => 'test-only', 'http_method_override' => false]);
                    $container->loadFromExtension('idempotency_guard', ['default_ttl' => 60]);
                    $container->register(OrderController::class)->setPublic(true);
                });
            }

            public function getProjectDir(): string
            {
                return dirname(__DIR__, 2);
            }

            public function getCacheDir(): string
            {
                // Keep generated Symfony containers outside OneDrive's read-only directory flags.
                return sys_get_temp_dir().'/idempotency-guard-bundle-tests/kernel-'.hash('sha256', $this->getProjectDir());
            }

            public function getLogDir(): string
            {
                return $this->getCacheDir().'/log';
            }
        };

        try {
            $responses = [];
            // Fresh cache keys make this test repeatable without clearing other records.
            $key = bin2hex(random_bytes(16));
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $request = Request::create('/orders', 'POST', server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_IDEMPOTENCY_KEY' => $key,
                ], content: '{"amount":100}');
                $request->attributes->set('_controller', [OrderController::class, 'create']);
                $responses[] = $kernel->handle($request);
            }

            self::assertSame(201, $responses[0]->getStatusCode());
            self::assertSame($responses[0]->getContent(), $responses[1]->getContent());
            self::assertSame(1, $kernel->getContainer()->get(OrderController::class)->executions);
        } finally {
            $kernel->shutdown();
        }
    }
}
