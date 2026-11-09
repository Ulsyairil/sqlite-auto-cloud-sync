<?php

declare(strict_types=1);

namespace SqliteSync\Yii\Config;

use PDO;
use yii\base\BaseObject;

/**
 * Sync settings for the Yii 2 adapter.
 *
 * Register as a component in `config/main.php`:
 *
 * ```php
 * 'components' => [
 *     'sqliteSync' => [
 *         'class' => \SqliteSync\Yii\Config\Sync::class,
 *         'deviceId' => getenv('SQLITE_SYNC_DEVICE_ID') ?: gethostname(),
 *         'tables' => ['notes' => ['pk' => 'id']],
 *     ],
 * ],
 * ```
 */
final class Sync extends BaseObject
{
    /** Unique per device. Generate: php -r 'echo bin2hex(random_bytes(8));' */
    public string $deviceId = '';

    /** Tables to sync: ['users' => ['pk' => 'id']] */
    public array $tables = [];

    public string $prefix = 'sqlite-sync';

    public int $chunkSize = 500;

    /** Absolute path to the SQLite file. Empty = @runtime/sqlite-sync.sqlite. */
    public string $database = '';

    /** Reuse an existing PDO connection instead of opening one. */
    public ?PDO $pdo = null;

    /** Yii DB component name to reuse instead of opening a PDO. */
    public string $db = '';

    /** ObjectStore instance, callable, or null for a local @runtime store. */
    public mixed $store = null;

    /** Run pull + push on the application after-request event. */
    public bool $autoSync = false;

    public bool $autoInstall = true;
}
