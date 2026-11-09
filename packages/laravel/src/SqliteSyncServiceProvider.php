<?php

declare(strict_types=1);

namespace SqliteSync\Laravel;

use Illuminate\Support\ServiceProvider;
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\Storage\ObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;
use SqliteSync\S3\S3ObjectStore;

final class SqliteSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sqlite-sync.php', 'sqlite-sync');

        $this->app->singleton(SyncEngine::class, function ($app): SyncEngine {
            $config = $app['config']->get('sqlite-sync');

            $pdo = $app['db']->connection($config['connection'] ?? null)->getPdo();

            $engine = new SyncEngine(
                $pdo,
                $this->makeStore($app, $config),
                new SyncConfig(
                    deviceId: (string) $config['device_id'],
                    tables: $config['tables'],
                    prefix: (string) $config['prefix'],
                    chunkSize: (int) $config['chunk_size'],
                ),
            );

            if ($config['auto_install'] ?? true) {
                $engine->install();
            }

            return $engine;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sqlite-sync.php' => config_path('sqlite-sync.php'),
            ], 'sqlite-sync');

            $this->commands([SyncCommand::class]);
        }

        // Triggers must exist before the app writes, otherwise the first request
        // is not captured. install() is a cheap no-op once up to date.
        if (config('sqlite-sync.auto_install')) {
            $this->app->make(SyncEngine::class);
        }

        if (config('sqlite-sync.auto_sync')) {
            $this->app->terminating(function (): void {
                try {
                    $this->app->make(SyncEngine::class)->sync();
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }

    private function makeStore($app, array $config): ObjectStore
    {
        $store = $config['store'] ?? ['driver' => 'local'];

        if ($store instanceof ObjectStore) {
            return $store;
        }

        if (is_string($store)) {
            $resolved = $app->make($store);
            if (!$resolved instanceof ObjectStore) {
                throw new \InvalidArgumentException(sprintf('%s must implement %s.', $store, ObjectStore::class));
            }

            return $resolved;
        }

        return match ($store['driver'] ?? 'local') {
            'local' => new LocalObjectStore($store['path'] ?? storage_path('app/sqlite-sync')),
            's3', 'disk', 'flysystem' => new S3ObjectStore(
                $app['filesystem']->disk($store['disk'] ?? 's3'),
                $store['root'] ?? $config['prefix'] ?? 'sqlite-sync',
            ),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown sqlite-sync store driver "%s".',
                $store['driver'] ?? '',
            )),
        };
    }
}
