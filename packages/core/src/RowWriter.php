<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use PDO;
use SqliteSync\Core\Exception\SyncException;

/**
 * Writes rows while suppressing change-log triggers. Shared by remote apply,
 * snapshot restore and repair.
 */
final class RowWriter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SyncConfig $config,
        private readonly SchemaInstaller $installer,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function upsert(string $table, array $payload, ?string $pkValue = null): void
    {
        $pkColumn = $this->config->primaryKey($table);
        if ($pkColumn === null) {
            throw new SyncException(sprintf('Table "%s" is not configured for sync.', $table));
        }

        $columns = array_values(array_filter(
            $this->installer->columns($table),
            static fn (string $column): bool => array_key_exists($column, $payload),
        ));

        if (!in_array($pkColumn, $columns, true)) {
            $columns[] = $pkColumn;
            $payload[$pkColumn] = $pkValue;
        }

        $quoted = array_map(SchemaInstaller::q(...), $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== $pkColumn,
        ));

        if ($updates === []) {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                SchemaInstaller::q($table),
                implode(', ', $quoted),
                $placeholders,
                SchemaInstaller::q($pkColumn),
            );
        } else {
            $assignments = array_map(
                static fn (string $column): string => SchemaInstaller::q($column) . ' = excluded.' . SchemaInstaller::q($column),
                $updates,
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                SchemaInstaller::q($table),
                implode(', ', $quoted),
                $placeholders,
                SchemaInstaller::q($pkColumn),
                implode(', ', $assignments),
            );
        }

        $this->pdo->prepare($sql)->execute(
            array_map(static fn (string $column) => $payload[$column], $columns),
        );
    }

    public function delete(string $table, string $pkValue): void
    {
        $pkColumn = $this->config->primaryKey($table);
        if ($pkColumn === null) {
            throw new SyncException(sprintf('Table "%s" is not configured for sync.', $table));
        }

        $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE %s = ?',
            SchemaInstaller::q($table),
            SchemaInstaller::q($pkColumn),
        ))->execute([$pkValue]);
    }

    public function guard(callable $write): void
    {
        $this->pdo->exec('INSERT OR IGNORE INTO _sync_guard (id) VALUES (1)');
        try {
            $write();
        } finally {
            $this->pdo->exec('DELETE FROM _sync_guard WHERE id = 1');
        }
    }
}
