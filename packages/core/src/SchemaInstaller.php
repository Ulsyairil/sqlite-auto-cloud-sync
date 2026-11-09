<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use PDO;
use SqliteSync\Core\Exception\SchemaMismatchException;
use SqliteSync\Core\Exception\SyncException;

/**
 * Installs the change-log tables and triggers. Safe to call repeatedly.
 */
final class SchemaInstaller
{
    public const META_TABLES = [
        '_sync_state',
        '_sync_log',
        '_sync_row_state',
        '_sync_applied',
        '_sync_guard',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SyncConfig $config,
    ) {
    }

    public function install(): void
    {
        $this->assertSqlite();
        $this->assertJson1();

        if ($this->isUpToDate()) {
            $this->assertDeviceId($this->getState('device_id'));

            return;
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS _sync_state (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS _sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tbl TEXT NOT NULL,
            pk TEXT NOT NULL,
            op TEXT NOT NULL,
            payload TEXT,
            changed_at INTEGER NOT NULL,
            device_id TEXT NOT NULL,
            batch_id TEXT
        )');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS _sync_log_pending
            ON _sync_log (id) WHERE batch_id IS NULL');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS _sync_row_state (
            tbl TEXT NOT NULL,
            pk TEXT NOT NULL,
            changed_at INTEGER NOT NULL,
            device_id TEXT NOT NULL,
            PRIMARY KEY (tbl, pk)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS _sync_applied (
            batch_key TEXT PRIMARY KEY,
            applied_at INTEGER NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS _sync_guard (
            id INTEGER PRIMARY KEY CHECK (id = 1)
        )');

        $previousHash = $this->getState('schema_hash');
        $newHash = $this->schemaHash();
        $this->assertDeviceId($this->getState('device_id'));

        $this->setState('device_id', $this->config->deviceId);
        $this->setState('schema_hash', $newHash);
        $this->setState('installed_at', (string) $this->nowMs());

        foreach ($this->config->tables() as $table => $pk) {
            $this->assertTableExists($table);
            $this->installTriggers($table, $pk);
            $this->bootstrapTable($table, $pk, $previousHash !== null && $previousHash !== $newHash);
        }
    }

    public function uninstall(): void
    {
        foreach ($this->config->tables() as $table => $pk) {
            foreach (['ai', 'au', 'ad'] as $kind) {
                $this->pdo->exec(sprintf('DROP TRIGGER IF EXISTS %s', self::q("_sync_{$kind}_{$table}")));
            }
        }
    }

    private function installTriggers(string $table, string $pk): void
    {
        $columns = $this->columns($table);
        if (!in_array($pk, $columns, true)) {
            throw new SyncException(sprintf('Primary key column "%s" not found in table "%s".', $pk, $table));
        }

        $qTable = self::q($table);
        $qPk = self::q($pk);
        $ts = "CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)";

        $rowStateSql = function (string $ref) use ($table, $qPk, $ts): string {
            return sprintf(
                'INSERT INTO _sync_row_state (tbl, pk, changed_at, device_id)
                 VALUES (%s, CAST(%s.%s AS TEXT), %s, %s)
                 ON CONFLICT (tbl, pk) DO UPDATE SET
                    changed_at = excluded.changed_at,
                    device_id = excluded.device_id;',
                self::s($table),
                $ref,
                $qPk,
                $ts,
                self::s($this->config->deviceId),
            );
        };

        $logSql = function (string $ref, string $op, string $payload) use ($table, $qPk): string {
            return sprintf(
                'INSERT INTO _sync_log (tbl, pk, op, payload, changed_at, device_id)
                 SELECT %s, CAST(%s.%s AS TEXT), %s, %s, changed_at, device_id
                 FROM _sync_row_state WHERE tbl = %s AND pk = CAST(%s.%s AS TEXT);',
                self::s($table),
                $ref,
                $qPk,
                self::s($op),
                $payload,
                self::s($table),
                $ref,
                $qPk,
            );
        };

        $jsonObject = function (string $ref) use ($columns): string {
            $pairs = [];
            foreach ($columns as $column) {
                $pairs[] = self::s($column) . ', ' . $ref . '.' . self::q($column);
            }

            return 'json_object(' . implode(', ', $pairs) . ')';
        };

        $this->pdo->exec(sprintf('DROP TRIGGER IF EXISTS %s', self::q("_sync_ai_{$table}")));
        $this->pdo->exec(sprintf(
            'CREATE TRIGGER %s AFTER INSERT ON %s
             WHEN NOT EXISTS (SELECT 1 FROM _sync_guard)
             BEGIN
                %s
                %s
             END',
            self::q("_sync_ai_{$table}"),
            $qTable,
            $rowStateSql('NEW'),
            $logSql('NEW', 'upsert', $jsonObject('NEW')),
        ));

        $this->pdo->exec(sprintf('DROP TRIGGER IF EXISTS %s', self::q("_sync_au_{$table}")));
        $this->pdo->exec(sprintf(
            'CREATE TRIGGER %s AFTER UPDATE ON %s
             WHEN NOT EXISTS (SELECT 1 FROM _sync_guard)
             BEGIN
                %s
                %s
             END',
            self::q("_sync_au_{$table}"),
            $qTable,
            $rowStateSql('NEW'),
            $logSql('NEW', 'upsert', $jsonObject('NEW')),
        ));

        $this->pdo->exec(sprintf('DROP TRIGGER IF EXISTS %s', self::q("_sync_ad_{$table}")));
        $this->pdo->exec(sprintf(
            'CREATE TRIGGER %s AFTER DELETE ON %s
             WHEN NOT EXISTS (SELECT 1 FROM _sync_guard)
             BEGIN
                %s
                %s
             END',
            self::q("_sync_ad_{$table}"),
            $qTable,
            $rowStateSql('OLD'),
            $logSql('OLD', 'delete', 'NULL'),
        ));
    }

    /**
     * Existing rows must reach other devices on first sync. Only runs when the
     * table has no tracked rows yet, unless $force is set after a schema change.
     */
    private function bootstrapTable(string $table, string $pk, bool $force = false): void
    {
        $tracked = (int) $this->pdo->query(sprintf(
            'SELECT COUNT(*) FROM _sync_row_state WHERE tbl = %s',
            self::s($table),
        ))->fetchColumn();

        if (!$force && $tracked > 0) {
            return;
        }

        $columns = $this->columns($table);
        $jsonPairs = [];
        foreach ($columns as $column) {
            $jsonPairs[] = self::s($column) . ', ' . self::q($column);
        }

        $ts = $this->nowMs();
        $this->pdo->beginTransaction();
        try {
            if ($force) {
                $this->pdo->exec(sprintf(
                    'DELETE FROM _sync_log WHERE batch_id IS NULL AND tbl = %s',
                    self::s($table),
                ));
            }
            $this->pdo->exec(sprintf(
                'INSERT INTO _sync_row_state (tbl, pk, changed_at, device_id)
                 SELECT %s, CAST(%s AS TEXT), %d, %s FROM %s WHERE true
                 ON CONFLICT (tbl, pk) DO UPDATE SET
                    changed_at = excluded.changed_at,
                    device_id = excluded.device_id',
                self::s($table),
                self::q($pk),
                $ts,
                self::s($this->config->deviceId),
                self::q($table),
            ));
            $this->pdo->exec(sprintf(
                'INSERT INTO _sync_log (tbl, pk, op, payload, changed_at, device_id)
                 SELECT %s, CAST(%s AS TEXT), %s, json_object(%s), %d, %s FROM %s',
                self::s($table),
                self::q($pk),
                self::s('upsert'),
                implode(', ', $jsonPairs),
                $ts,
                self::s($this->config->deviceId),
                self::q($table),
            ));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        $stmt = $this->pdo->query('PRAGMA table_xinfo(' . self::q($table) . ')');
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) ($row['hidden'] ?? 0) !== 0) {
                continue;
            }
            $columns[] = (string) $row['name'];
        }

        return $columns;
    }

    public function schemaHash(): string
    {
        return substr(
            hash('sha256', json_encode($this->schemaDescriptor(), JSON_THROW_ON_ERROR)),
            0,
            16,
        );
    }

    /**
     * @return array<string, array{pk: string, columns: list<string>}>
     */
    public function schemaDescriptor(): array
    {
        $schema = [];
        foreach ($this->config->tables() as $table => $pk) {
            $schema[$table] = [
                'pk' => $pk,
                'columns' => $this->columns($table),
            ];
        }

        return $schema;
    }

    /**
     * Remote payloads may contain fewer columns (older schema) but never columns
     * or tables the local database does not have yet.
     *
     * @param array<string, mixed> $remote
     */
    public function assertCompatible(array $remote): void
    {
        $local = $this->schemaDescriptor();

        foreach ($remote as $table => $definition) {
            if (!is_string($table) || !isset($local[$table])) {
                throw new SchemaMismatchException(sprintf(
                    'Remote data contains table "%s" that is not configured locally. '
                    . 'Run migrations and add it to the sync tables.',
                    (string) $table,
                ));
            }

            if (!is_array($definition)) {
                continue;
            }

            if (isset($definition['pk']) && $definition['pk'] !== $local[$table]['pk']) {
                throw new SchemaMismatchException(sprintf(
                    'Primary key mismatch for table "%s": remote "%s", local "%s".',
                    $table,
                    (string) $definition['pk'],
                    $local[$table]['pk'],
                ));
            }

            $columns = is_array($definition['columns'] ?? null) ? $definition['columns'] : [];
            $missing = array_values(array_diff($columns, $local[$table]['columns']));
            if ($missing !== []) {
                throw new SchemaMismatchException(sprintf(
                    'Remote data for table "%s" has columns missing locally: %s. '
                    . 'Run migrations before syncing.',
                    $table,
                    implode(', ', $missing),
                ));
            }
        }
    }

    private function assertTableExists(string $table): void
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . self::s($table),
        );
        if ($stmt->fetchColumn() === false) {
            throw new SyncException(sprintf('Table "%s" does not exist.', $table));
        }
    }

    private function isUpToDate(): bool
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN "
            . "('_sync_state', '_sync_log', '_sync_row_state', '_sync_applied', '_sync_guard')",
        );
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count(self::META_TABLES)) {
            return false;
        }

        $stored = $this->getState('schema_hash');
        if ($stored === null || $stored !== $this->schemaHash()) {
            return false;
        }

        foreach ($this->config->tables() as $table => $pk) {
            foreach (['ai', 'au', 'ad'] as $kind) {
                $stmt = $this->pdo->query(sprintf(
                    "SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND name = %s",
                    self::s("_sync_{$kind}_{$table}"),
                ));
                if ($stmt->fetchColumn() === false) {
                    return false;
                }
            }
        }

        return true;
    }

    private function assertDeviceId(?string $stored): void
    {
        if ($stored !== null && $stored !== $this->config->deviceId) {
            throw new SyncException(sprintf(
                'device_id changed from "%s" to "%s". Device identity must stay stable; '
                . 'use a new database or keep the original id.',
                $stored,
                $this->config->deviceId,
            ));
        }
    }

    private function getState(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM _sync_state WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function assertSqlite(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'sqlite') {
            throw new SyncException(sprintf('Only sqlite is supported, got "%s".', $driver));
        }
    }

    private function assertJson1(): void
    {
        try {
            $this->pdo->query("SELECT json_object('a', 1)")->fetchColumn();
        } catch (\Throwable $e) {
            throw new SyncException('SQLite JSON1 extension is required.', 0, $e);
        }
    }

    private function setState(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO _sync_state (key, value) VALUES (:key, :value)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value',
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    private function nowMs(): int
    {
        return (int) $this->pdo->query(
            "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)",
        )->fetchColumn();
    }

    public static function q(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public static function s(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
