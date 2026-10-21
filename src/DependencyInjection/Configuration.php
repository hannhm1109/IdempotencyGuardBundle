<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('idempotency_guard');
        $tree->getRootNode()
            ->children()
                ->integerNode('default_ttl')->min(1)->defaultValue(86400)->end()
                ->scalarNode('header_name')->defaultValue('Idempotency-Key')
                    ->validate()
                        ->ifTrue(static fn ($name): bool => !is_string($name)
                            || preg_match('/\A[!#$%&\x27*+.^_`|~0-9A-Za-z-]+\z/D', $name) !== 1
                            || in_array(strtolower($name), ['authorization', 'proxy-authorization', 'cookie'], true))
                        ->thenInvalid('header_name must be an HTTP field name dedicated to idempotency, not credentials.')
                    ->end()
                ->end()
                ->scalarNode('storage')->cannotBeEmpty()->defaultValue('cache.app')
                    ->validate()->ifTrue(static fn ($id): bool => !is_string($id))->thenInvalid('storage must be a cache pool service ID.')->end()
                ->end()
            ->end();

        return $tree;
    }
}
