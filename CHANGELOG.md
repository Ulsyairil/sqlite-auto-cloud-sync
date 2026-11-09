# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Snapshot / restore for bootstrap of new devices and large databases.
- `verify` command to check row versions, applied batches and schema hash.
- `repair` command to back-fill missing row versions for rows written before `install()`.
- Compaction keeps batches newer than the latest snapshot automatically.
- RowWriter shared by remote apply, snapshot restore and repair.
- Compactor watermarks now include snapshot baselines.
- Full CLI for snapshot/restore/verify/repair in Laravel, CodeIgniter and Symfony.
- GitHub Actions test matrix for PHP 8.1–8.4.

### Changed
- `SyncEngine::applyEntry` delegates upsert/delete to `RowWriter`.
- Pull skips batches covered by a restored snapshot.
- `install()` idempotent; re-logs rows after schema change.
- `push()` deletes uploaded log rows immediately.

### Fixed
- Delete trigger used `OLD` instead of `NEW`.
- Batch timestamps monotonically increasing (`last_batch_at` state).

### Removed
- `assertSchemaCompatible` private method (moved to `SchemaInstaller`).

## [0.1.0] - 2026-11-08

### Added
- Core engine with row-level change-log, LWW merge, tombstones.
- LocalObjectStore for tests and local development.
- Schema installer with triggers and bootstrap of existing rows.
- Conflict resolution: timestamp + device_id tie-break.
- Schema drift detection (hash + column subset validation).
- Laravel adapter: ServiceProvider, config, artisan command, auto-sync on terminate.
- CodeIgniter 4 adapter: library, config, spark command, event hook.
- Symfony bundle: DI config, console command, auto-sync on kernel terminate.
- S3ObjectStore via Flysystem (AWS S3, R2, MinIO, Supabase Storage).
- Demo script and architecture documentation.
- Unit tests covering bootstrap, update, LWW, delete, tombstone, schema mismatch, tie-break, install/pull idempotent, device identity, schema change, log pruning, compaction.

---

**Note:** Versions < 1.0 are unstable and may introduce breaking changes at any time.