<?php

declare(strict_types=1);

namespace SqliteSync\Yii;

use PDO;
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\Storage\ObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;
use SqliteSync\Yii\Config\Sync as SyncSettings;
use Yii;
use yii\base\Application;
use yii\base\Event;
use yii\db\Connection;

/**
 * Yii 2 adapter. Mirrors the Laravel/CodeIgniter/Symfony adapters: build one
 * SyncEngine from config, install triggers before writes, optionally sync after
 * the request.
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
     * Install triggers and register the auto-sync hook. Call from a bootstrap
     * class or `config/main.php`.
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

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, static function () use ($settings): void {
            try {
                self::engine($settings)->sync();
            } catch (\Throwable $e) {
                Yii::error('sqlite-sync: ' . $e->getMessage(), __METHOD__);
            }
        });
    }

    public static function settings(): SyncSettings
    {
        $app = Yii::$app;

        if ($app !== null && $app->has('sqliteSync')) {
            $settings = $app->get('sqliteSync');
            if ($settings instanceof SyncSettings) {
                return $settings;
            }
        }

        $params = $app?->params['sqliteSync'] ?? null;
        if ($params instanceof SyncSettings) {
            return $params;
        }
        if (is_array($params)) {
            return new SyncSettings($params);
        }

        return new SyncSettings();
    }

    private static function pdo(SyncSettings $settings): PDO
    {
        if ($settings->pdo !== null) {
            return $settings->pdo;
        }

        if ($settings->db !== '') {
            $connection = Yii::$app?->get($settings->db);
            if (!$connection instanceof Connection) {
                throw new \InvalidArgumentException(sprintf(
                    'Yii DB component "%s" must be an instance of %s.',
                    $settings->db,
                    Connection::class,
                ));
            }

            return $connection->getMasterPdo();
        }

        $database = $settings->database !== ''
            ? $settings->database
            : Yii::getAlias('@runtime/sqlite-sync.sqlite');

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

        return new LocalObjectStore(Yii::getAlias('@runtime/sqlite-sync'));
    }

    private static function defaultDeviceId(): string
    {
        $base = Yii::$app?->basePath ?? __DIR__;

        return 'yii-' . substr(sha1($base), 0, 8);
    }
}
