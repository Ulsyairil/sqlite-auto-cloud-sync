# SQLite Auto Cloud Sync

[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4?logo=php&logoColor=white)](composer.json)
[![Packagist Version](https://img.shields.io/packagist/v/ulsyairil/sqlite-auto-cloud-sync.svg)](https://packagist.org/packages/ulsyairil/sqlite-auto-cloud-sync)
[![Packagist Downloads](https://img.shields.io/packagist/dt/ulsyairil/sqlite-auto-cloud-sync.svg)](https://packagist.org/packages/ulsyairil/sqlite-auto-cloud-sync)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-21%20passing-brightgreen.svg)](#test)
[![External calls](https://img.shields.io/badge/external%20calls-zero-success.svg)](#why-local)

Sync SQLite across devices, row by row, with object storage as the cloud. Framework-agnostic core with thin adapters for Laravel, CodeIgniter 4, Symfony, Yii 2, and CakePHP.

Not file replication. Every `INSERT`/`UPDATE`/`DELETE` is captured by SQLite triggers into a change-log, uploaded as immutable JSON batches, then merged on other devices using **last-write-wins per row**.

```
app write ──trigger──> _sync_log ──push()──> S3 / R2 / MinIO
                                                   │
app read  <──apply──── merge LWW  <──pull()────────┘
```

## Status

- Core + 5 adapters are in place, test suite passes (`phpunit`: 21 tests, 57 assertions).
- Batch compaction + per-device checkpoints are in place; old batches are deleted once every other device has applied them.
- Automated tests currently cover the core engine. Framework adapters are linted and follow the official APIs, but do not yet have per-framework integration tests.
- Not yet available: snapshot/bootstrap for large DBs, payload encryption, and automatic schema sync. See [docs/architecture.md](docs/architecture.md#batasan).

## Install

```bash
composer require ulsyairil/sqlite-auto-cloud-sync
```

Everything is included: core engine, S3/R2/MinIO adapter, Laravel, CodeIgniter 4, Symfony, Yii 2, and CakePHP.

## Quick start (plain PHP)

```php
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;

$pdo = new PDO('sqlite:' . __DIR__ . '/app.sqlite');
$store = new LocalObjectStore(__DIR__ . '/cloud-mirror'); // replace with S3ObjectStore in production

$engine = new SyncEngine(
    $pdo,
    $store,
    new SyncConfig(
        deviceId: 'laptop-01',                  // unique per device
        tables: ['notes' => ['pk' => 'id']],
    ),
);

$engine->install();   // idempotent: creates _sync_* tables + triggers + bootstraps existing rows
$engine->sync();      // pull first, then push
```

Run the two-device demo:

```bash
composer install
php examples/demo.php
```

## S3 / R2 / MinIO

```bash
composer require ulsyairil/sqlite-auto-cloud-sync league/flysystem-aws-s3-v3
```

```php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use SqliteSync\S3\S3ObjectStore;

$client = new S3Client([
    'region' => 'auto',
    'endpoint' => 'https://<account>.r2.cloudflarestorage.com',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => getenv('S3_KEY'),
        'secret' => getenv('S3_SECRET'),
    ],
]);

$store = new S3ObjectStore(
    new Filesystem(new AwsS3V3Adapter($client, 'my-bucket')),
    'sqlite-sync',
);
```

Do **not** put S3 credentials in the client. A device only needs access to its own bucket prefix. For multi-tenant, use one prefix per user/workspace.

## Laravel

```bash
composer require ulsyairil/sqlite-auto-cloud-sync
php artisan vendor:publish --tag=sqlite-sync
```

`config/sqlite-sync.php`:

```php
'device_id' => env('SQLITE_SYNC_DEVICE_ID'),
'tables' => ['users' => ['pk' => 'id'], 'posts' => ['pk' => 'id']],
'store' => ['driver' => 's3', 'disk' => 's3', 'root' => 'sqlite-sync'],
'auto_sync' => true,
```

Register the `s3` disk in `config/filesystems.php` as usual. Auto-sync runs in the `terminating` callback, so it does not hold the response. Sync failures are reported via `report()`, and the request still succeeds.

```bash
php artisan sqlite-sync status
php artisan sqlite-sync sync
```

## CodeIgniter 4

```bash
composer require ulsyairil/sqlite-auto-cloud-sync
```

Copy `packages/codeigniter/src/Config/Sync.php` to `app/Config/Sync.php`, then fill in `deviceId`, `tables`, `database`, and `store`:

```php
public string $deviceId = 'ci-device-01';
public array $tables = ['notes' => ['pk' => 'id']];
public mixed $store = null; // or fn () => new S3ObjectStore(...)
public bool $autoSync = true;
```

Register the hook in `app/Config/Events.php`:

```php
\SqliteSync\CodeIgniter\SqliteSync::boot();
```

```bash
php spark sqlite-sync sync
```

The adapter opens its own PDO to the SQLite file (CI4's SQLite3 driver does not expose PDO). Enable WAL on both connections.

## Symfony

```bash
composer require ulsyairil/sqlite-auto-cloud-sync
```

```yaml
# config/packages/sqlite_sync.yaml
sqlite_sync:
    device_id: '%env(SQLITE_SYNC_DEVICE_ID)%'
    tables:
        users: { pk: id }
        posts: { pk: id }
    prefix: sqlite-sync
    pdo: 'pdo'                 # PDO service id for SQLite
    store: 'sqlite_sync.store' # ObjectStore service id
    auto_install: true
    auto_sync: true
```

Register `SqliteSyncBundle` in `config/bundles.php`, then define the `sqlite_sync.store` service:

```yaml
services:
    sqlite_sync.store:
        class: SqliteSync\S3\S3ObjectStore
        arguments: ['@s3.filesystem', 'sqlite-sync']
```

```bash
bin/console sqlite-sync sync
```

## Yii 2

```bash
composer require ulsyairil/sqlite-auto-cloud-sync
```

Register the component, bootstrap, and command in `config/main.php`:

```php
use SqliteSync\Yii\Config\Sync;

return [
    'bootstrap' => [\SqliteSync\Yii\Bootstrap::class],
    'components' => [
        'sqliteSync' => [
            'class' => Sync::class,
            'deviceId' => getenv('SQLITE_SYNC_DEVICE_ID') ?: gethostname(),
            'tables' => ['notes' => ['pk' => 'id']],
            'database' => dirname(__DIR__) . '/runtime/app.sqlite', // empty = @runtime/sqlite-sync.sqlite
            'store' => null, // default LocalObjectStore(@runtime/sqlite-sync)
            'autoSync' => true,
        ],
    ],
    'controllerMap' => [
        'sqlite-sync' => \SqliteSync\Yii\Commands\SqliteSyncController::class,
    ],
];
```

`Bootstrap` runs `install()` before the request writes. `autoSync` runs pull + push on the `Application::EVENT_AFTER_REQUEST` event; sync failures are logged via `Yii::error()`, and the request still succeeds. For S3/R2/MinIO, set `store` to a callable that returns an `ObjectStore`.

```bash
php yii sqlite-sync                  # status
php yii sqlite-sync/sync
php yii sqlite-sync/compact --keep=1
php yii sqlite-sync/restore --force
```

## CakePHP

```bash
composer require ulsyairil/sqlite-auto-cloud-sync
```

Write the configuration in `config/bootstrap.php`:

```php
use Cake\Core\Configure;

Configure::write('SqliteSync', [
    'deviceId' => env('SQLITE_SYNC_DEVICE_ID') ?: gethostname(),
    'tables' => ['notes' => ['pk' => 'id']],
    'connection' => 'default', // CakePHP SQLite connection; or 'database' => TMP . 'app.sqlite'
    'store' => null,           // default LocalObjectStore(TMP . 'sqlite-sync')
    'autoSync' => true,
]);

\SqliteSync\CakePHP\SqliteSync::boot(); // install triggers before the request writes
```

Register the middleware and command in `src/Application.php`:

```php
use SqliteSync\CakePHP\Command\SyncCommand;
use SqliteSync\CakePHP\Middleware\AutoSyncMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    return $middlewareQueue->add(new AutoSyncMiddleware());
}

public function console(CommandCollection $commands): CommandCollection
{
    return $commands->add('sqlite-sync', SyncCommand::class);
}
```

Middleware runs `install()` before the request and `sync()` after the response is built. Sync failures are logged via `Log::error()` and do not fail the request. For large datasets, run sync from a queue/CLI instead of the request lifecycle.

```bash
bin/cake sqlite-sync                  # status
bin/cake sqlite-sync sync
bin/cake sqlite-sync compact --keep=1
bin/cake sqlite-sync restore --force
```

## Conflict rules

Per row, not per file:

1. Larger `changed_at` (epoch ms) wins.
2. If equal, `device_id` is compared lexicographically; the larger one wins.
3. Deletes are stored as tombstones (`op = 'delete'`), so an old update cannot resurrect a row.
4. Local changes that have not been pushed and lose to a remote change are removed from `_sync_log` so they cannot overwrite it back.

All devices converge to the same value without server coordination. There is no locking between devices.

## Commands

| Framework | Status | Push | Pull | Sync | Compact | Snapshot | Restore | Verify | Repair |
|---|---|---|---|---|---|---|---|---|---|
| Laravel | `artisan sqlite-sync status` | `push` | `pull` | `sync` | `compact --keep=1` | `snapshot` | `restore --force` | `verify` | `repair` |
| CodeIgniter | `spark sqlite-sync status` | `push` | `pull` | `sync` | `compact 1` | `snapshot` | `restore true` | `verify` | `repair` |
| Symfony | `console sqlite-sync status` | `push` | `pull` | `sync` | `compact --keep=1` | `snapshot` | `restore --force=true` | `verify` | `repair` |
| Yii 2 | `yii sqlite-sync` | `sqlite-sync/push` | `sqlite-sync/pull` | `sqlite-sync/sync` | `sqlite-sync/compact --keep=1` | `sqlite-sync/snapshot` | `sqlite-sync/restore --force` | `sqlite-sync/verify` | `sqlite-sync/repair` |
| CakePHP | `cake sqlite-sync` | `sqlite-sync push` | `sqlite-sync pull` | `sqlite-sync sync` | `sqlite-sync compact --keep=1` | `sqlite-sync snapshot` | `sqlite-sync restore --force` | `sqlite-sync verify` | `sqlite-sync repair` |

### Advanced operations

```bash
# First device: create a snapshot (bootstrap large DB / before compaction)
php artisan sqlite-sync snapshot

# New device: restore from the latest snapshot
php artisan sqlite-sync restore --force

# New device that already has data (use force)
php artisan sqlite-sync restore --force

# Check consistency
php artisan sqlite-sync verify

# Repair missing row_state (e.g. data written before install())
php artisan sqlite-sync repair
```

After `restore`, a new device will automatically `pull` batches created after the snapshot on the next `sync`.

## Production checklist

- `PRAGMA journal_mode=WAL` and `busy_timeout` on every connection.
- `device_id` must be unique and persistent. Do not regenerate it — the identity is used for tie-breaking.
- Device clocks must be NTP-synced. Large clock skew makes LWW pick the wrong winner.
- Back up the bucket before a schema upgrade. Schema mismatch deliberately throws an error instead of merging silently.
- Restrict bucket access per device; payloads are not encrypted.
- Run `sync()` from a queue worker for large datasets, not in the request lifecycle.
- Schedule `compact` from cron **after every device has synced at least once**. Devices that have never pulled can lose history.
- Compaction keeps the newest `--keep` batches per device (default 1) as a margin.
- The local log is pruned automatically on every push; `_sync_log` only contains changes that have not been uploaded.

## Roadmap

| Area | Plan |
|---|---|
| **Security** | Payload encryption (AES-GCM per device), signed batches, device revocation |
| **Schema** | Auto-migration detection & safe apply, column rename/type change handling |
| **Bootstrap** | Streaming snapshot for large DB (>100MB), delta snapshot support |
| **Observability** | Structured logs, metrics (Prometheus), tracing (OpenTelemetry) |
| **Testing** | Integration tests per framework (Laravel/CI4/Symfony/Yii/CakePHP), chaos testing (network partition, clock skew) |
| **DX** | `sqlite-sync doctor` diagnostics, web UI to inspect batches/status, VS Code extension |
| **Platform** | Flutter/Dart client (mobile), Kotlin/Swift native bindings via FFI |
| **Cloud** | Managed sync service (hosted bucket + API), conflict resolution webhook |

## Test

```bash
composer install
./vendor/bin/phpunit --testdox
```

Tests use two separate SQLite databases and one `LocalObjectStore`: bootstrap, update, LWW, delete, tombstone, schema mismatch, tie-break, idempotent install, idempotent pull, device identity, schema migration, log pruning, compaction, and checkpoints.
