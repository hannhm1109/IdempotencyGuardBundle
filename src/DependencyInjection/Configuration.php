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
                ->scalarNode('storage')->cannotBeEmpty()->defaultValue('cache.app')->end()
            ->end();

        return $tree;
    }
}
