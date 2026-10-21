<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Unit;

use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Hanane\IdempotencyGuardBundle\DependencyInjection\Configuration;
use Hanane\IdempotencyGuardBundle\DependencyInjection\IdempotencyGuardExtension;
use Hanane\IdempotencyGuardBundle\Storage\IdempotencyStorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsAndCustomConfiguration(): void
    {
        $processor = new Processor();
        self::assertSame(['default_ttl' => 86400, 'header_name' => 'Idempotency-Key', 'storage' => 'cache.app'], $processor->processConfiguration(new Configuration(), []));
        $custom = ['default_ttl' => 60, 'header_name' => 'X-Operation-Key', 'storage' => 'cache.operations'];
        self::assertSame($custom, $processor->processConfiguration(new Configuration(), [$custom]));
    }

    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationFailsDuringContainerBuild(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'zero TTL' => [['default_ttl' => 0]];
        yield 'negative TTL' => [['default_ttl' => -1]];
        yield 'fractional TTL' => [['default_ttl' => 1.5]];
        yield 'empty header' => [['header_name' => '']];
        yield 'header with whitespace' => [['header_name' => 'Invalid Header']];
        yield 'header injection' => [['header_name' => "Key\r\nX-Other"]];
        yield 'credential header' => [['header_name' => 'Authorization']];
        yield 'cookie header' => [['header_name' => 'cOoKiE']];
        yield 'proxy credential header' => [['header_name' => 'Proxy-Authorization']];
        yield 'non-string header' => [['header_name' => true]];
        yield 'empty storage' => [['storage' => '']];
        yield 'non-string storage' => [['storage' => true]];
        yield 'unknown setting' => [['enable_everything' => true]];
    }

    public function testAnApplicationStorageAliasIsPreserved(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(IdempotencyStorageInterface::class, 'app.custom_storage');
        (new IdempotencyGuardExtension())->load([], $container);
        self::assertSame('app.custom_storage', (string) $container->getAlias(IdempotencyStorageInterface::class));
    }

    public function testAnApplicationStorageDefinitionIsPreserved(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->register(IdempotencyStorageInterface::class, 'App\\CustomStorage');
        (new IdempotencyGuardExtension())->load([], $container);
        self::assertSame($definition, $container->getDefinition(IdempotencyStorageInterface::class));
        self::assertFalse($container->hasAlias(IdempotencyStorageInterface::class));
    }

    #[DataProvider('invalidTtl')]
    public function testAttributeRejectsNonPositiveTtl(int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Idempotent($ttl);
    }

    public static function invalidTtl(): iterable
    {
        yield [0];
        yield [-1];
    }
}
