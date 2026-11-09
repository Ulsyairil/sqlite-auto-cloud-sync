<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use PDO;
use SqliteSync\Core\Exception\SyncException;
use SqliteSync\Core\Storage\ObjectStore;

/**
 * Full-database bootstrap for new devices and large datasets.
 *
 * A snapshot is immutable JSON holding every synced row plus its last-known
 * row version. New devices restore the newest snapshot, then pull only batches
 * created after the snapshot watermarks.
 */
final class Snapshotter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ObjectStore $store,
        private readonly SyncConfig $config,
        private readonly SchemaInstaller $installer,
        private readonly Compactor $compactor,
        private readonly RowWriter $writer,
    ) {
    }

    public function create(): SnapshotResult
    {
        $schema = $this->installer->schemaDescriptor();
        $tables = [];
        $rows = 0;

        $this->transaction(function () use ($schema, &$tables, &$rows): void {
            foreach ($schema as $table => $definition) {
                $columns = array_map(SchemaInstaller::q(...), $definition['columns']);
                $statement = $this->pdo->query(sprintf(
                    'SELECT %s FROM %s',
                    implode(', ', $columns),
                    SchemaInstaller::q($table),
                ));
                $tableRows = $statement->fetchAll(PDO::FETCH_ASSOC);

                $states = [];
                $stateStatement = $this->pdo->prepare(
                    'SELECT pk, changed_at, device_id FROM _sync_row_state WHERE tbl = ?',
                );
                $stateStatement->execute([$table]);
                foreach ($stateStatement->fetchAll(PDO::FETCH_ASSOC) as $state) {
                    $states[(string) $state['pk']] = [
                        'changed_at' => (int) $state['changed_at'],
                        'device_id' => (string) $state['device_id'],
                    ];
                }

                $tables[$table] = ['rows' => $tableRows, 'states' => $states];
                $rows += count($tableRows);
            }
        });

        $sources = $this->snapshotSources();
        $createdAt = $this->nowMs();
        $snapshotId = bin2hex(random_bytes(12));
        $key = $this->config->keyForSnapshot($this->config->deviceId, $createdAt, $snapshotId);

        $body = json_encode([
            'snapshot_id' => $snapshotId,
            'device_id' => $this->config->deviceId,
            'created_at' => $createdAt,
            'schema_hash' => $this->installer->schemaHash(),
            'schema' => $schema,
            'sources' => $sources,
            'tables' => $tables,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->store->put($key, $body);

        return new SnapshotResult($key, $rows, strlen($body), $sources);
    }

    public function restore(bool $force = false): SnapshotResult
    {
        $latest = $this->latest();
        if ($latest === null) {
            throw new SyncException('No snapshot found in object storage. Create one with the snapshot command first.');
        }

        $payload = $latest['payload'];
        $schema = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
        $this->installer->assertCompatible($schema);

        $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];
        $rowCount = 0;
        foreach ($tables as $table => $data) {
            if ($this->config->hasTable((string) $table) && is_array($data['rows'] ?? null)) {
                $rowCount += count($data['rows']);
            }
        }

        if (!$force && $this->existingRows() > 0) {
            throw new SyncException(
                'Restore target is not empty. Use force=true only on a database you are willing to overwrite.',
            );
        }

        $createdAt = (int) ($payload['created_at'] ?? 0);
        $deviceId = (string) ($payload['device_id'] ?? '');
        $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];

        $this->transaction(function () use ($tables, $force, $createdAt, $deviceId, $latest, $sources): void {
            if ($force) {
                $this->pdo->exec('DELETE FROM _sync_applied');
                $this->pdo->exec('DELETE FROM _sync_log');
                $this->pdo->exec('DELETE FROM _sync_row_state');
                foreach ($this->config->tables() as $table => $pk) {
                    $this->pdo->exec('DELETE FROM ' . SchemaInstaller::q($table));
                }
            }

            $this->writer->guard(function () use ($tables): void {
                foreach ($tables as $table => $data) {
                    if (!$this->config->hasTable((string) $table) || !is_array($data['rows'] ?? null)) {
                        continue;
                    }
                    foreach ($data['rows'] as $row) {
                        if (is_array($row)) {
                            $this->writer->upsert((string) $table, $row);
                        }
                    }
                }
            });

            $stateStatement = $this->pdo->prepare(
                'INSERT INTO _sync_row_state (tbl, pk, changed_at, device_id) VALUES (?, ?, ?, ?)
                 ON CONFLICT (tbl, pk) DO UPDATE SET
                    changed_at = excluded.changed_at,
                    device_id = excluded.device_id',
            );

            foreach ($tables as $table => $data) {
                $pkColumn = $this->config->primaryKey((string) $table);
                if ($pkColumn === null || !is_array($data['rows'] ?? null)) {
                    continue;
                }
                $states = is_array($data['states'] ?? null) ? $data['states'] : [];

                foreach ($data['rows'] as $row) {
                    if (!is_array($row) || !array_key_exists($pkColumn, $row)) {
                        continue;
                    }
                    $pk = (string) $row[$pkColumn];
                    $state = is_array($states[$pk] ?? null) ? $states[$pk] : null;
                    $stateStatement->execute([
                        (string) $table,
                        $pk,
                        $state['changed_at'] ?? $createdAt,
                        $state['device_id'] ?? $deviceId,
                    ]);
                }
            }

            $this->setState('snapshot_key', $latest['key']);
            $this->setState('snapshot_sources', json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        });

        return new SnapshotResult($latest['key'], $rowCount, 0, array_filter(
            $sources,
            static fn ($value): bool => is_string($value),
        ));
    }

    /**
     * @return array{key: string, payload: array<string, mixed>}|null
     */
    public function latest(): ?array
    {
        $best = null;
        $bestAt = -1;
        $bestKey = '';

        foreach ($this->store->list($this->config->snapshotPrefix()) as $key) {
            if (!str_ends_with($key, '.json')) {
                continue;
            }
            $raw = $this->store->get($key);
            if ($raw === null) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                continue;
            }
            $createdAt = (int) ($payload['created_at'] ?? 0);
            if ($createdAt > $bestAt || ($createdAt === $bestAt && strcmp($key, $bestKey) > 0)) {
                $bestAt = $createdAt;
                $bestKey = $key;
                $best = ['key' => $key, 'payload' => $payload];
            }
        }

        return $best;
    }

    /**
     * Batches reflected in this snapshot. Own batches count because the local
     * database already contains them; remote batches only when applied.
     *
     * @return array<string, string>
     */
    private function snapshotSources(): array
    {
        $sources = $this->compactor->watermarks();
        $own = SyncConfig::safeSegment($this->config->deviceId);

        foreach ($this->store->list($this->config->batchPrefix()) as $key) {
            if ($this->config->segmentOfBatchKey($key) !== $own) {
                continue;
            }
            if (!isset($sources[$own]) || strcmp($key, $sources[$own]) > 0) {
                $sources[$own] = $key;
            }
        }

        return $sources;
    }

    private function existingRows(): int
    {
        $total = 0;
        foreach ($this->config->tables() as $table => $pk) {
            $total += (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ' . SchemaInstaller::q($table),
            )->fetchColumn();
        }

        return $total;
    }

    private function transaction(callable $work): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('SAVEPOINT sqlite_sync_snapshot');
            try {
                $work();
                $this->pdo->exec('RELEASE SAVEPOINT sqlite_sync_snapshot');
            } catch (\Throwable $e) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sqlite_sync_snapshot');
                $this->pdo->exec('RELEASE SAVEPOINT sqlite_sync_snapshot');
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

    private function setState(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO _sync_state (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value',
        )->execute([$key, $value]);
    }

    private function nowMs(): int
    {
        return (int) $this->pdo->query(
            "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)",
        )->fetchColumn();
    }
}
