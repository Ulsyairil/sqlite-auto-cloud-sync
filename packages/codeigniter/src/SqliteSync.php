<?php

declare(strict_types=1);

namespace SqliteSync\CodeIgniter;

use CodeIgniter\Events\Events;
use PDO;
use SqliteSync\CodeIgniter\Config\Sync as SyncSettings;
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\Storage\ObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;

final class SqliteSync
{
    private static ?SyncEngine $engine = null;

    public static function engine(?SyncSettings $settings = null): SyncEngine
    {
        if ($settings === null && self::$engine !== null) {
            return self::$engine;
        }

        $settings ??= self::settings();

        $pdo = $settings->pdo ?? new PDO(
            'sqlite:' . ($settings->database !== '' ? $settings->database : WRITEPATH . 'database.sqlite'),
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $engine = new SyncEngine(
            $pdo,
            self::store($settings),
            new SyncConfig(
                deviceId: $settings->deviceId !== '' ? $settings->deviceId : 'ci-' . substr(sha1(WRITEPATH), 0, 8),
                tables: $settings->tables,
                prefix: $settings->prefix,
                chunkSize: $settings->chunkSize,
            ),
        );

        if ($settings->autoInstall) {
            $engine->install();
        }

        return self::$engine = $engine;
    }

    /**
     * Register the post_system hook. Call from app/Config/Events.php:
     *   \SqliteSync\CodeIgniter\SqliteSync::boot();
     */
    public static function boot(?SyncSettings $settings = null): void
    {
        $settings ??= self::settings();

        if ($settings->autoInstall) {
            self::engine($settings); // install triggers before the request writes
        }

        if (!$settings->autoSync) {
            return;
        }

        Events::on('post_system', static function () use ($settings): void {
            try {
                self::engine($settings)->sync();
            } catch (\Throwable $e) {
                log_message('error', 'sqlite-sync: ' . $e->getMessage());
            }
        });
    }

    private static function settings(): SyncSettings
    {
        if (class_exists(\Config\Sync::class)) {
            return new \Config\Sync();
        }

        return new SyncSettings();
    }

    private static function store(SyncSettings $settings): ObjectStore
    {
        $store = $settings->store;
        if ($store instanceof ObjectStore) {
            return $store;
        }
        if (is_callable($store)) {
            $resolved = $store();
            if (!$resolved instanceof ObjectStore) {
                throw new \InvalidArgumentException('sqlite-sync store callable must return an ObjectStore.');
            }

            return $resolved;
        }

        return new LocalObjectStore(WRITEPATH . 'sqlite-sync');
    }
}
