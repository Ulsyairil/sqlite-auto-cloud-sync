<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use PDO;
use SqliteSync\Core\Storage\ObjectStore;

/**
 * Read-only consistency checks plus an explicit repair for rows whose triggers
 * were missing (for example rows written before install()).
 */
final class Verifier
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ObjectStore $store,
        private readonly SyncConfig $config,
        private readonly SchemaInstaller $installer,
    ) {
    }

    public function verify(): VerifyResult
    {
        $issues = [];

        foreach ($this->config->tables() as $table => $pk) {
            $missing = $this->missingRowStateCount($table, $pk);
            if ($missing > 0) {
                $issues[] = sprintf(
                    '%d row(s) in "%s" have no sync version; run repair.',
                    $missing,
                    $table,
                );
            }
        }

        $missingBatches = 0;
        foreach ($this->appliedKeys() as $key) {
            if ($this->store->get($key) === null) {
                $missingBatches++;
            }
        }
        if ($missingBatches > 0) {
            $issues[] = sprintf(
                '%d applied batch(es) are missing from object storage.',
                $missingBatches,
            );
        }

        $stored = $this->getState('schema_hash');
        $current = $this->installer->schemaHash();
        if ($stored !== null && $stored !== $current) {
            $issues[] = sprintf(
                'Schema hash changed (%s -> %s); run install()/migrations.',
                $stored,
                $current,
            );
        }

        return new VerifyResult($issues === [], $issues);
    }

    public function repair(): int
    {
        $repaired = 0;
        $changedAt = $this->nowMs();

        foreach ($this->config->tables() as $table => $pk) {
            $missing = $this->missingPks($table, $pk);
            if ($missing === []) {
                continue;
            }

            $columns = $this->installer->columns($table);
            $quoted = array_map(SchemaInstaller::q(...), $columns);
            $rowStatement = $this->pdo->prepare(sprintf(
                'SELECT %s FROM %s WHERE CAST(%s AS TEXT) = ?',
                implode(', ', $quoted),
                SchemaInstaller::q($table),
                SchemaInstaller::q($pk),
            ));
            $stateStatement = $this->pdo->prepare(
                'INSERT INTO _sync_row_state (tbl, pk, changed_at, device_id) VALUES (?, ?, ?, ?)
                 ON CONFLICT (tbl, pk) DO UPDATE SET
                    changed_at = excluded.changed_at,
                    device_id = excluded.device_id',
            );
            $logStatement = $this->pdo->prepare(
                'INSERT INTO _sync_log (tbl, pk, op, payload, changed_at, device_id)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );

            foreach ($missing as $rowPk) {
                $rowStatement->execute([$rowPk]);
                $row = $rowStatement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    continue;
                }

                $this->pdo->beginTransaction();
                try {
                    $stateStatement->execute([$table, $rowPk, $changedAt, $this->config->deviceId]);
                    $logStatement->execute([
                        $table,
                        $rowPk,
                        'upsert',
                        json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        $changedAt,
                        $this->config->deviceId,
                    ]);
                    $this->pdo->commit();
                    $repaired++;
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }

        return $repaired;
    }

    /** @return list<string> */
    private function missingPks(string $table, string $pk): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT CAST(%s AS TEXT) FROM %s
             WHERE CAST(%s AS TEXT) NOT IN (
                SELECT pk FROM _sync_row_state WHERE tbl = ?
             )',
            SchemaInstaller::q($pk),
            SchemaInstaller::q($table),
            SchemaInstaller::q($pk),
        ));
        $statement->execute([$table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function missingRowStateCount(string $table, string $pk): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s
             WHERE CAST(%s AS TEXT) NOT IN (
                SELECT pk FROM _sync_row_state WHERE tbl = ?
             )',
            SchemaInstaller::q($table),
            SchemaInstaller::q($pk),
        ));
        $statement->execute([$table]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function appliedKeys(): array
    {
        return array_map(
            'strval',
            $this->pdo->query('SELECT batch_key FROM _sync_applied')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function getState(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM _sync_state WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function nowMs(): int
    {
        return (int) $this->pdo->query(
            "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)",
        )->fetchColumn();
    }
}
