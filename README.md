# SQLite Auto Cloud Sync

[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4?logo=php&logoColor=white)](composer.json)
[![Packagist Version](https://img.shields.io/packagist/v/ulsyairil/sqlite-sync.svg)](https://packagist.org/packages/ulsyairil/sqlite-sync)
[![Packagist Downloads](https://img.shields.io/packagist/dt/ulsyairil/sqlite-sync.svg)](https://packagist.org/packages/ulsyairil/sqlite-sync)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-21%20passing-brightgreen.svg)](#test)
[![External calls](https://img.shields.io/badge/external%20calls-zero-success.svg)](#why-local)

Sync SQLite antar device, level baris, dengan object storage sebagai cloud. Core framework-agnostic, adapter tipis untuk Laravel, CodeIgniter 4, dan Symfony.

Bukan replikasi file. Setiap `INSERT`/`UPDATE`/`DELETE` ditangkap trigger SQLite ke change-log, di-upload sebagai batch JSON immutable, lalu di-merge di device lain dengan aturan **last-write-wins per baris**.

```
app write ──trigger──> _sync_log ──push()──> S3 / R2 / MinIO
                                                   │
app read  <──apply──── merge LWW  <──pull()────────┘
```

## Status

- Core + 3 adapter sudah ada, test suite lulus (`phpunit`: 21 test, 57 assertion).
- Kompaksi batch + checkpoint per device sudah ada; batch lama dihapus setelah semua device lain menerapkannya.
- Test otomatis saat ini mencakup core engine. Adapter framework sudah di-lint dan mengikuti API resmi, tapi belum punya integration test per framework.
- Belum ada: snapshot/bootstrap untuk DB besar, enkripsi payload, dan sinkronisasi schema otomatis. Lihat [docs/architecture.md](docs/architecture.md#batasan).

## Paket (Packagist vendor `ulsyairil`)

| Paket | Isi | Install |
|---|---|---|
| `ulsyairil/sqlite-sync` | Core engine, change-log, LWW merger, `LocalObjectStore` | `composer require ulsyairil/sqlite-sync` |
| `ulsyairil/sqlite-sync-s3` | `S3ObjectStore` via Flysystem (S3, R2, MinIO, Supabase) | `composer require ulsyairil/sqlite-sync-s3 league/flysystem-aws-s3-v3` |
| `ulsyairil/sqlite-sync-laravel` | ServiceProvider, config, `php artisan sqlite-sync` | `composer require ulsyairil/sqlite-sync-laravel` |
| `ulsyairil/sqlite-sync-codeigniter` | Library, config, `php spark sqlite-sync` | `composer require ulsyairil/sqlite-sync-codeigniter` |
| `ulsyairil/sqlite-sync-symfony` | Bundle, DI config, `bin/console sqlite-sync` | `composer require ulsyairil/sqlite-sync-symfony` |

Repo ini monorepo. Untuk publish ke Packagist, pisahkan tiap folder `packages/*` jadi repo sendiri, atau gunakan `composer` path repository saat development.

## Quick start (PHP murni)

```php
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;

$pdo = new PDO('sqlite:' . __DIR__ . '/app.sqlite');
$store = new LocalObjectStore(__DIR__ . '/cloud-mirror'); // ganti S3ObjectStore di produksi

$engine = new SyncEngine(
    $pdo,
    $store,
    new SyncConfig(
        deviceId: 'laptop-01',                  // unik per device
        tables: ['notes' => ['pk' => 'id']],
    ),
);

$engine->install();   // idempotent: bikin tabel _sync_* + trigger + bootstrap row lama
$engine->sync();      // pull dulu, lalu push
```

Jalankan demo dua device:

```bash
composer install
php examples/demo.php
```

## S3 / R2 / MinIO

```bash
composer require sqlite-sync/s3 league/flysystem-aws-s3-v3
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

Kredensial S3 **jangan** ditaruh di client. Device hanya butuh akses ke prefix bucket-nya sendiri. Untuk multi-tenant, satu prefix per user/workspace.

## Laravel

```bash
composer require sqlite-sync/laravel sqlite-sync/s3
php artisan vendor:publish --tag=sqlite-sync
```

`config/sqlite-sync.php`:

```php
'device_id' => env('SQLITE_SYNC_DEVICE_ID'),
'tables' => ['users' => ['pk' => 'id'], 'posts' => ['pk' => 'id']],
'store' => ['driver' => 's3', 'disk' => 's3', 'root' => 'sqlite-sync'],
'auto_sync' => true,
```

Daftarkan disk `s3` di `config/filesystems.php` seperti biasa. Auto-sync jalan di `terminating` callback, jadi tidak menahan response. Kegagalan sync dilaporkan lewat `report()`, request tetap sukses.

```bash
php artisan sqlite-sync status
php artisan sqlite-sync sync
```

## CodeIgniter 4

```bash
composer require sqlite-sync/codeigniter sqlite-sync/s3
```

Copy `packages/codeigniter/src/Config/Sync.php` ke `app/Config/Sync.php`, isi `deviceId`, `tables`, `database`, dan `store`:

```php
public string $deviceId = 'ci-device-01';
public array $tables = ['notes' => ['pk' => 'id']];
public mixed $store = null; // atau fn () => new S3ObjectStore(...)
public bool $autoSync = true;
```

Daftarkan hook di `app/Config/Events.php`:

```php
\SqliteSync\CodeIgniter\SqliteSync::boot();
```

```bash
php spark sqlite-sync sync
```

Adapter membuka PDO sendiri ke file SQLite (driver SQLite3 CI4 tidak mengekspos PDO). Aktifkan WAL di kedua koneksi.

## Symfony

```bash
composer require sqlite-sync/symfony sqlite-sync/s3
```

```yaml
# config/packages/sqlite_sync.yaml
sqlite_sync:
    device_id: '%env(SQLITE_SYNC_DEVICE_ID)%'
    tables:
        users: { pk: id }
        posts: { pk: id }
    prefix: sqlite-sync
    pdo: 'pdo'                 # service id PDO SQLite
    store: 'sqlite_sync.store' # service id ObjectStore
    auto_install: true
    auto_sync: true
```

Daftarkan `SqliteSyncBundle` di `config/bundles.php`, lalu definisikan service `sqlite_sync.store`:

```yaml
services:
    sqlite_sync.store:
        class: SqliteSync\S3\S3ObjectStore
        arguments: ['@s3.filesystem', 'sqlite-sync']
```

```bash
bin/console sqlite-sync sync
```

## Aturan konflik

Per baris, bukan per file:

1. `changed_at` (epoch ms) lebih besar menang.
2. Jika sama, `device_id` dibandingkan lexicographic; yang lebih besar menang.
3. Delete disimpan sebagai tombstone (`op = 'delete'`), jadi baris tidak hidup lagi oleh update lama.
4. Perubahan lokal yang belum ter-push dan kalah dari remote dihapus dari `_sync_log` agar tidak menimpa balik.

Semua device konvergen ke nilai yang sama tanpa koordinasi server. Tidak ada locking antar device.

## Perintah

| Framework | Status | Push | Pull | Sync | Compact | Snapshot | Restore | Verify | Repair |
|---|---|---|---|---|---|---|---|---|---|
| Laravel | `artisan sqlite-sync status` | `push` | `pull` | `sync` | `compact --keep=1` | `snapshot` | `restore --force` | `verify` | `repair` |
| CodeIgniter | `spark sqlite-sync status` | `push` | `pull` | `sync` | `compact 1` | `snapshot` | `restore true` | `verify` | `repair` |
| Symfony | `console sqlite-sync status` | `push` | `pull` | `sync` | `compact --keep=1` | `snapshot` | `restore --force=true` | `verify` | `repair` |

### Operasi lanjutan

```bash
# Device pertama: buat snapshot (bootstrap DB besar / sebelum kompaksi)
php artisan sqlite-sync snapshot

# Device baru: restore dari snapshot terbaru
php artisan sqlite-sync restore --force

# Device baru yang sudah punya data (gunakan force)
php artisan sqlite-sync restore --force

# Cek konsistensi
php artisan sqlite-sync verify

# Perbaiki row_state yang hilang (mis. data ditulis sebelum install())
php artisan sqlite-sync repair
```

Setelah `restore`, device baru secara otomatis akan `pull` batch-batch yang dibuat setelah snapshot saat `sync` berikutnya.

## Checklist produksi

- `PRAGMA journal_mode=WAL` dan `busy_timeout` di setiap koneksi.
- `device_id` unik dan persisten. Jangan regenerate — identitas dipakai untuk tie-break.
- Jam device harus NTP-sync. Clock skew besar membuat LWW salah pilih pemenang.
- Backup bucket sebelum upgrade schema. Schema mismatch sengaja melempar error, bukan merge diam-diam.
- Batasi akses bucket per device; payload tidak terenkripsi.
- Jalankan `sync()` dari queue worker untuk dataset besar, bukan di request lifecycle.
- Jadwalkan `compact` dari cron **setelah semua device pernah sync minimal sekali**. Device yang belum pernah pull bisa kehilangan history.
- Kompaksi menyisakan `--keep` batch terbaru per device (default 1) sebagai margin.
- Log lokal otomatis dipangkas setiap push; `_sync_log` hanya berisi perubahan yang belum ter-upload.

## Roadmap

| Area | Rencana |
|---|---|
| **Security** | Payload encryption (AES-GCM per device), signed batches, device revocation |
| **Schema** | Auto-migration detection & safe apply, column rename/type change handling |
| **Bootstrap** | Streaming snapshot for large DB (>100MB), delta snapshot support |
| **Observability** | Structured logs, metrics (Prometheus), tracing (OpenTelemetry) |
| **Testing** | Integration tests per framework (Laravel/CI4/Symfony), chaos testing (network partition, clock skew) |
| **DX** | `sqlite-sync doctor` diagnostics, web UI untuk inspect batch/status, VS Code extension |
| **Platform** | Flutter/Dart client (mobile), Kotlin/Swift native bindings via FFI |
| **Cloud** | Managed sync service (hosted bucket + API), conflict resolution webhook |

## Test

```bash
composer install
./vendor/bin/phpunit --testdox
```

Test memakai dua database SQLite terpisah dan satu `LocalObjectStore`: bootstrap, update, LWW, delete, tombstone, schema mismatch, tie-break, install idempotent, pull idempotent, device identity, migrasi schema, pruning log, kompaksi dan checkpoint.
