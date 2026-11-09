<?php

declare(strict_types=1);

namespace SqliteSync\CakePHP;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PDO;
use SqliteSync\CakePHP\Config\Sync as SyncSettings;
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\Storage\ObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;

/**
 * CakePHP adapter. Mirrors the other framework adapters: build one SyncEngine
 * from config, install triggers before writes, optionally sync after the
 * response is built (via AutoSyncMiddleware).
 */
final class SqliteSync
{
    private static ?SyncEngine $engine = null;

    public static function engine(?SyncSettings $settings = null): SyncEngine
    {
        if ($settings === null && self::$engine !== null) {
            return self::$engine;
        }

        $settings ??= self::settings();

        $pdo = self::pdo($settings);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $engine = new SyncEngine(
            $pdo,
            self::store($settings),
            new SyncConfig(
                deviceId: $settings->deviceId !== '' ? $settings->deviceId : self::defaultDeviceId(),
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
     * Install triggers before the request writes. Call from
     * `config/bootstrap.php`.
     */
    public static function boot(?SyncSettings $settings = null): void
    {
        $settings ??= self::settings();

        if ($settings->autoInstall) {
            self::engine($settings);
        }
    }

    public static function settings(): SyncSettings
    {
        $config = Configure::read('SqliteSync');

        if ($config instanceof SyncSettings) {
            return $config;
        }
        if (is_array($config)) {
            return new SyncSettings($config);
        }

        return new SyncSettings();
    }

    private static function pdo(SyncSettings $settings): PDO
    {
        if ($settings->pdo !== null) {
            return $settings->pdo;
        }

        if ($settings->connection !== '') {
            $pdo = ConnectionManager::get($settings->connection)->getDriver()->getConnection();
            if (!$pdo instanceof PDO) {
                throw new \InvalidArgumentException(sprintf(
                    'CakePHP connection "%s" did not provide a PDO instance.',
                    $settings->connection,
                ));
            }

            return $pdo;
        }

        $database = $settings->database !== ''
            ? $settings->database
            : TMP . 'sqlite-sync.sqlite';

        return new PDO('sqlite:' . $database);
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

        return new LocalObjectStore(TMP . 'sqlite-sync');
    }

    private static function defaultDeviceId(): string
    {
        $base = defined('ROOT') ? ROOT : __DIR__;

        return 'cake-' . substr(sha1($base), 0, 8);
    }
}
