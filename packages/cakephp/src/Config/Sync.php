<?php

declare(strict_types=1);

namespace SqliteSync\CakePHP\Config;

use PDO;

/**
 * Sync settings for the CakePHP adapter.
 *
 * Write them in `config/bootstrap.php`:
 *
 * ```php
 * use Cake\Core\Configure;
 *
 * Configure::write('SqliteSync', [
 *     'deviceId' => env('SQLITE_SYNC_DEVICE_ID') ?: gethostname(),
 *     'tables' => ['notes' => ['pk' => 'id']],
 *     'connection' => 'default',
 * ]);
 * ```
 */
final class Sync
{
    /** Unique per device. Generate: php -r 'echo bin2hex(random_bytes(8));' */
    public string $deviceId = '';

    /** Tables to sync: ['users' => ['pk' => 'id']] */
    public array $tables = [];

    public string $prefix = 'sqlite-sync';

    public int $chunkSize = 500;

    /** Absolute path to the SQLite file. Empty = TMP . 'sqlite-sync.sqlite'. */
    public string $database = '';

    /** Reuse an existing PDO connection instead of opening one. */
    public ?PDO $pdo = null;

    /** CakePHP connection name to reuse instead of opening a PDO. */
    public string $connection = '';

    /** ObjectStore instance, callable, or null for a local TMP store. */
    public mixed $store = null;

    /** Run pull + push after the middleware response is built. */
    public bool $autoSync = false;

    public bool $autoInstall = true;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
