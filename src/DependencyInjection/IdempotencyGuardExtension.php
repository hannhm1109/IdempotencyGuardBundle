<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\DependencyInjection;

use Hanane\IdempotencyGuardBundle\EventSubscriber\IdempotencySubscriber;
use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class IdempotencyGuardExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->register(RequestFingerprint::class);
        $container->register(StorageKeyFactory::class)
            ->setArguments([new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $container->register(CacheIdempotencyStorage::class)
            ->setArguments([new Reference($config['storage'])]);
        if (!$container->hasAlias(IdempotencyStorageInterface::class) && !$container->hasDefinition(IdempotencyStorageInterface::class)) {
            $container->setAlias(IdempotencyStorageInterface::class, CacheIdempotencyStorage::class);
        }

        $container->register('idempotency_guard.lock_store', FlockStore::class)
            ->setArguments(['%kernel.cache_dir%/idempotency_guard/locks']);
        $container->register('idempotency_guard.lock_factory', LockFactory::class)
            ->setArguments([new Reference('idempotency_guard.lock_store')]);

        $container->register(IdempotencySubscriber::class)
            ->setArguments([
                new Reference(IdempotencyStorageInterface::class),
                new Reference(RequestFingerprint::class),
                new Reference(StorageKeyFactory::class),
                new Reference('idempotency_guard.lock_factory'),
                $config['default_ttl'],
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('kernel.event_subscriber');
    }
}
