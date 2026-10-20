<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Integration;

use Hanane\IdempotencyGuardBundle\IdempotencyGuardBundle;
use Hanane\IdempotencyGuardBundle\Tests\Fixtures\OrderController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;

final class BundleBootTest extends TestCase
{
    public function testBundleBootsAndRegistersItsSubscriberInARealSymfonyContainer(): void
    {
        $kernel = new class('test', false) extends Kernel {
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
                return $this->getProjectDir().'/var/test-kernel';
            }

            public function getLogDir(): string
            {
                return $this->getProjectDir().'/var/log';
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
