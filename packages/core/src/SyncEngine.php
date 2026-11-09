<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use PDO;
use SqliteSync\Core\Exception\SchemaMismatchException;
use SqliteSync\Core\Exception\SyncException;
use SqliteSync\Core\Storage\ObjectStore;

/**
 * Row-level SQLite sync engine.
 *
 * Local writes are captured by triggers into `_sync_log`. `push()` uploads
 * immutable JSON batches to object storage. `pull()` downloads batches from
 * other devices and merges them with last-write-wins per row.
 */
final class SyncEngine
{
    private readonly SchemaInstaller $installer;
    private readonly Compactor $compactor;
    private readonly RowWriter $writer;
    private readonly Snapshotter $snapshotter;
    private readonly Verifier $verifier;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ObjectStore $store,
        private readonly SyncConfig $config,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->installer = new SchemaInstaller($this->pdo, $this->config);
        $this->compactor = new Compactor($this->pdo, $this->store, $this->config);
        $this->writer = new RowWriter($this->pdo, $this->config, $this->installer);
        $this->snapshotter = new Snapshotter(
            $this->pdo,
            $this->store,
            $this->config,
            $this->installer,
            $this->compactor,
            $this->writer,
        );
        $this->verifier = new Verifier($this->pdo, $this->store, $this->config, $this->installer);
    }

    public function install(): void
    {
        $this->installer->install();
    }

    public function config(): SyncConfig
    {
        return $this->config;
    }

    /**
     * Pull remote changes first, then push local ones. Converges in one call.
     */
    public function sync(): SyncResult
    {
        $this->assertInstalled();

        return (new SyncResult())
            ->merge($this->pull())
            ->merge($this->push());
    }

    public function pull(): SyncResult
    {
        $this->assertInstalled();
        $result = new SyncResult();

        $keys = $this->store->list($this->config->batchPrefix());
        $ownSegment = '/' . SyncConfig::safeSegment($this->config->deviceId) . '/';
        $snapshotSources = $this->snapshotSources();

        foreach ($keys as $key) {
            if (!str_ends_with($key, '.json')) {
                continue;
            }
            if (str_contains($key, $ownSegment)) {
                continue;
            }
            $source = $this->config->segmentOfBatchKey($key);
            if ($source !== null
                && isset($snapshotSources[$source])
                && strcmp($key, $snapshotSources[$source]) <= 0
            ) {
                continue; // already included in the restored snapshot
            }
            if ($this->isApplied($key)) {
                continue;
            }

            $raw = $this->store->get($key);
            if ($raw === null) {
                continue;
            }

            $batch = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $remoteHash = (string) ($batch['schema_hash'] ?? '');
            $localHash = $this->state('schema_hash') ?? $this->installer->schemaHash();

            if (isset($batch['schema']) && is_array($batch['schema'])) {
                $this->installer->assertCompatible($batch['schema']);
            } elseif ($remoteHash !== '' && $remoteHash !== $localHash) {
                throw SchemaMismatchException::between($localHash, $remoteHash);
            }

            $this->transaction(function () use ($batch, $key, $result): void {
                foreach ($batch['entries'] ?? [] as $entry) {
                    $this->applyEntry($entry, $result);
                }
                $stmt = $this->pdo->prepare(
                    'INSERT OR REPLACE INTO _sync_applied (batch_key, applied_at) VALUES (?, ?)',
                );
                $stmt->execute([$key, $this->nowMs()]);
            });

            $result->pulledBatches++;
        }

        $this->compactor->writeCheckpoint();
        $this->setState('last_pull_at', (string) $this->nowMs());

        return $result;
    }

    public function push(): SyncResult
    {
        $this->assertInstalled();
        $result = new SyncResult();

        do {
            $rows = $this->pendingRows($this->config->chunkSize);
            if ($rows === []) {
                break;
            }

            $batchId = bin2hex(random_bytes(12));
            $createdAt = $this->nextBatchTimestamp();
            $entries = [];
            $ids = [];

            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
                $entries[] = [
                    'tbl' => (string) $row['tbl'],
                    'pk' => (string) $row['pk'],
                    'op' => (string) $row['op'],
                    'payload' => $row['payload'] === null ? null : json_decode((string) $row['payload'], true),
                    'changed_at' => (int) $row['changed_at'],
                    'device_id' => (string) $row['device_id'],
                ];
            }

            $key = $this->config->keyFor($this->config->deviceId, $createdAt, $batchId);
            $body = json_encode([
                'batch_id' => $batchId,
                'device_id' => $this->config->deviceId,
                'created_at' => $createdAt,
                'schema_hash' => $this->state('schema_hash') ?? $this->installer->schemaHash(),
                'schema' => $this->installer->schemaDescriptor(),
                'entries' => $entries,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->store->put($key, $body);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // Uploaded entries no longer need a local copy. If this delete
            // fails, the rows stay pending and get re-uploaded; apply is
            // idempotent, so that is safe.
            $this->pdo->prepare(
                sprintf('DELETE FROM _sync_log WHERE id IN (%s)', $placeholders),
            )->execute($ids);

            $result->pushedBatches++;
            $result->pushedEntries += count($entries);
        } while (true);

        if ($result->pushedBatches > 0) {
            $this->setState('last_push_at', (string) $this->nowMs());
        }

        return $result;
    }

    /**
     * Delete uploaded batches that every other registered device already applied.
     * Safe to call from cron. No-op until another device has pulled and written
     * a checkpoint.
     */
    public function compact(int $keepPerDevice = 1): CompactionResult
    {
        $this->assertInstalled();

        return $this->compactor->compact($keepPerDevice);
    }

    /**
     * Upload a full snapshot of every synced table. Use before compaction and
     * whenever a new device needs to join.
     */
    public function snapshot(): SnapshotResult
    {
        $this->assertInstalled();

        return $this->snapshotter->create();
    }

    /**
     * Load the newest snapshot into this database, then pull newer batches.
     * Refuses to overwrite non-empty tables unless $force is true.
     */
    public function restore(bool $force = false): SnapshotResult
    {
        $this->assertInstalled();

        return $this->snapshotter->restore($force);
    }

    public function verify(): VerifyResult
    {
        $this->assertInstalled();

        return $this->verifier->verify();
    }

    /**
     * Give rows written while triggers were missing a version and a pending log
     * entry. Returns the number of repaired rows.
     */
    public function repair(): int
    {
        $this->assertInstalled();

        return $this->verifier->repair();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $this->assertInstalled();

        $pending = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM _sync_log WHERE batch_id IS NULL',
        )->fetchColumn();

        return [
            'device_id' => $this->config->deviceId,
            'schema_hash' => $this->state('schema_hash'),
            'pending_entries' => $pending,
            'applied_batches' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM _sync_applied',
            )->fetchColumn(),
            'tracked_rows' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM _sync_row_state',
            )->fetchColumn(),
            'last_push_at' => $this->state('last_push_at'),
            'last_pull_at' => $this->state('last_pull_at'),
            'tables' => $this->config->tables(),
        ];
    }

    /**
     * Full state of one row, useful for debugging conflict resolution.
     *
     * @return array{changed_at: int, device_id: string}|null
     */
    public function rowState(string $table, string $pk): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT changed_at, device_id FROM _sync_row_state WHERE tbl = ? AND pk = ?',
        );
        $stmt->execute([$table, $pk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['changed_at' => (int) $row['changed_at'], 'device_id' => (string) $row['device_id']];
    }

    /** @param array<string, mixed> $entry */
    private function applyEntry(array $entry, SyncResult $result): void
    {
        $table = (string) ($entry['tbl'] ?? '');
        $pk = (string) ($entry['pk'] ?? '');

        if ($table === '' || $pk === '' || !$this->config->hasTable($table)) {
            $result->skippedEntries++;

            return;
        }

        $op = (string) ($entry['op'] ?? '');
        $payload = $entry['payload'] ?? null;
        if ($op !== 'delete' && !is_array($payload)) {
            $result->skippedEntries++;

            return;
        }

        $remoteAt = (int) ($entry['changed_at'] ?? 0);
        $remoteDevice = (string) ($entry['device_id'] ?? '');
        $local = $this->rowState($table, $pk);

        if ($local !== null) {
            if ($local['changed_at'] === $remoteAt && $local['device_id'] === $remoteDevice) {
                $result->skippedEntries++;

                return;
            }
            if (!$this->remoteWins($remoteAt, $remoteDevice, $local)) {
                $result->conflicts++;

                return;
            }
        }

        // A remote winner supersedes any local change not uploaded yet.
        $stmt = $this->pdo->prepare(
            'DELETE FROM _sync_log WHERE batch_id IS NULL AND tbl = ? AND pk = ?',
        );
        $stmt->execute([$table, $pk]);

        $this->writer->guard(function () use ($op, $table, $pk, $payload): void {
            if ($op === 'delete') {
                $this->writer->delete($table, $pk);

                return;
            }
            /** @var array<string, mixed> $payload */
            $this->writer->upsert($table, $payload, $pk);
        });

        $stmt = $this->pdo->prepare(
            'INSERT INTO _sync_row_state (tbl, pk, changed_at, device_id) VALUES (?, ?, ?, ?)
             ON CONFLICT (tbl, pk) DO UPDATE SET
                changed_at = excluded.changed_at,
                device_id = excluded.device_id',
        );
        $stmt->execute([$table, $pk, $remoteAt, $remoteDevice]);

        $result->appliedEntries++;
    }

    /** @param array{changed_at: int, device_id: string} $local */
    private function remoteWins(int $remoteAt, string $remoteDevice, array $local): bool
    {
        if ($remoteAt !== $local['changed_at']) {
            return $remoteAt > $local['changed_at'];
        }

        // Deterministic tie-break so every device converges on the same winner.
        return strcmp($remoteDevice, $local['device_id']) > 0;
    }

    /** @return list<array<string, mixed>> */
    private function pendingRows(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tbl, pk, op, payload, changed_at, device_id
             FROM _sync_log WHERE batch_id IS NULL ORDER BY id LIMIT :limit',
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isApplied(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM _sync_applied WHERE batch_key = ?');
        $stmt->execute([$key]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string, string> */
    private function snapshotSources(): array
    {
        $decoded = json_decode($this->state('snapshot_sources') ?? '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $sources = [];
        foreach ($decoded as $source => $watermark) {
            if (is_string($source) && is_string($watermark)) {
                $sources[$source] = $watermark;
            }
        }

        return $sources;
    }

    private function transaction(callable $work): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('SAVEPOINT sqlite_sync');
            try {
                $work();
                $this->pdo->exec('RELEASE SAVEPOINT sqlite_sync');
            } catch (\Throwable $e) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sqlite_sync');
                $this->pdo->exec('RELEASE SAVEPOINT sqlite_sync');
                throw $e;
            }

            return;
        }

        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function state(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM _sync_state WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function setState(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO _sync_state (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value',
        );
        $stmt->execute([$key, $value]);
    }

    private function nowMs(): int
    {
        return (int) $this->pdo->query(
            "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)",
        )->fetchColumn();
    }

    /**
     * Batch keys must sort in creation order per device, otherwise a checkpoint
     * watermark could point past a batch another device has not applied yet.
     * Two pushes in the same millisecond therefore get distinct timestamps.
     */
    private function nextBatchTimestamp(): int
    {
        $now = $this->nowMs();
        $last = (int) ($this->state('last_batch_at') ?? 0);
        $next = $now > $last ? $now : $last + 1;
        $this->setState('last_batch_at', (string) $next);

        return $next;
    }

    private function assertInstalled(): void
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '_sync_log'",
        );
        if ($stmt->fetchColumn() === false) {
            throw new SyncException('Sync tables missing. Call install() first.');
        }
    }
}
