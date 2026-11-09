<?php

declare(strict_types=1);

namespace SqliteSync\Symfony\DependencyInjection;

use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;
use SqliteSync\Symfony\Command\SyncCommand;
use SqliteSync\Symfony\EventListener\AutoSyncListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class SqliteSyncExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('sqlite_sync.device_id', $config['device_id']);
        $container->setParameter('sqlite_sync.prefix', $config['prefix']);

        $container->register(SyncConfig::class)
            ->setArguments([
                $config['device_id'],
                $config['tables'],
                $config['prefix'],
                $config['chunk_size'],
            ]);

        $container->register(SyncEngine::class)
            ->setArguments([
                new Reference($config['pdo']),
                new Reference($config['store']),
                new Reference(SyncConfig::class),
            ])
            ->setPublic(true);

        $container->register(SyncCommand::class)
            ->setArguments([new Reference(SyncEngine::class)])
            ->addTag('console.command');

        $container->register(AutoSyncListener::class)
            ->setArguments([
                new Reference(SyncEngine::class),
                $config['auto_install'],
                $config['auto_sync'],
            ])
            ->addTag('kernel.event_subscriber');
    }

    public function getAlias(): string
    {
        return 'sqlite_sync';
    }
}
