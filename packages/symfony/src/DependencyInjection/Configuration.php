<?php

declare(strict_types=1);

namespace SqliteSync\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sqlite_sync');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('device_id')->isRequired()->cannotBeEmpty()->end()
                ->arrayNode('tables')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('pk')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('prefix')->defaultValue('sqlite-sync')->end()
                ->integerNode('chunk_size')->defaultValue(500)->min(1)->end()
                ->scalarNode('pdo')->defaultValue('pdo')->info('Service id of the SQLite PDO connection.')->end()
                ->scalarNode('store')->defaultValue('sqlite_sync.store')->info('Service id implementing ObjectStore.')->end()
                ->booleanNode('auto_install')->defaultTrue()->end()
                ->booleanNode('auto_sync')->defaultFalse()->end()
            ->end();

        return $treeBuilder;
    }
}
