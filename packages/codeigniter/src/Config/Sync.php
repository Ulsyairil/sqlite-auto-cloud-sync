<?php

declare(strict_types=1);

namespace SqliteSync\CodeIgniter\Config;

use CodeIgniter\Config\BaseConfig;
use PDO;

/**
 * Copy to app/Config/Sync.php and edit there.
 */
class Sync extends BaseConfig
{
    /** Unique per device. Generate: php -r 'echo bin2hex(random_bytes(8));' */
    public string $deviceId = '';

    /** Tables to sync: ['users' => ['pk' => 'id']] */
    public array $tables = [];

    public string $prefix = 'sqlite-sync';

    public int $chunkSize = 500;

    /** Absolute path to the SQLite file. Empty = WRITEPATH . 'database.sqlite'. */
    public string $database = '';

    /** ObjectStore instance, callable, or null for a local WRITEPATH store. */
    public mixed $store = null;

    /** Reuse an existing PDO connection instead of opening one. */
    public ?PDO $pdo = null;

    /** Run pull + push on the post_system event. */
    public bool $autoSync = false;

    public bool $autoInstall = true;
}
