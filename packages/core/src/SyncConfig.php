<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use InvalidArgumentException;

/**
 * Immutable sync configuration.
 *
 * tables format:
 *   ['users' => ['pk' => 'id'], 'posts' => ['pk' => 'uuid']]
 */
final class SyncConfig
{
    /** @var array<string, string> table => primary key column */
    private array $tables;

    /**
     * @param array<string, array{pk: string}|string> $tables
     */
    public function __construct(
        public readonly string $deviceId,
        array $tables,
        public readonly string $prefix = 'sqlite-sync',
        public readonly int $chunkSize = 500,
    ) {
        if ($deviceId === '') {
            throw new InvalidArgumentException('deviceId must not be empty.');
        }
        if ($tables === []) {
            throw new InvalidArgumentException('At least one table must be configured.');
        }
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('chunkSize must be >= 1.');
        }

        $normalized = [];
        foreach ($tables as $table => $options) {
            $pk = is_array($options) ? ($options['pk'] ?? null) : $options;
            if (!is_string($pk) || $pk === '') {
                throw new InvalidArgumentException(sprintf('Missing primary key for table "%s".', $table));
            }
            $normalized[$table] = $pk;
        }

        $this->tables = $normalized;
    }

    /** @return array<string, string> */
    public function tables(): array
    {
        return $this->tables;
    }

    public function primaryKey(string $table): ?string
    {
        return $this->tables[$table] ?? null;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function normalizePrefix(): string
    {
        return trim($this->prefix, '/');
    }

    public function batchPrefix(): string
    {
        return $this->normalizePrefix() . '/batches';
    }

    public function snapshotPrefix(): string
    {
        return $this->normalizePrefix() . '/snapshots';
    }

    public function statePrefix(): string
    {
        return $this->normalizePrefix() . '/state';
    }

    public function keyFor(string $deviceId, int $timestampMs, string $batchId): string
    {
        return sprintf(
            '%s/%s/%013d-%s.json',
            $this->batchPrefix(),
            self::safeSegment($deviceId),
            $timestampMs,
            $batchId,
        );
    }

    public function keyForSnapshot(string $deviceId, int $timestampMs, string $snapshotId): string
    {
        return sprintf(
            '%s/%s/%013d-%s.json',
            $this->snapshotPrefix(),
            self::safeSegment($deviceId),
            $timestampMs,
            $snapshotId,
        );
    }

    public function segmentOfBatchKey(string $key): ?string
    {
        $base = $this->batchPrefix() . '/';
        if (!str_starts_with($key, $base)) {
            return null;
        }

        $parts = explode('/', substr($key, strlen($base)));

        return count($parts) >= 2 && $parts[0] !== '' ? $parts[0] : null;
    }

    public function segmentOfSnapshotKey(string $key): ?string
    {
        $base = $this->snapshotPrefix() . '/';
        if (!str_starts_with($key, $base)) {
            return null;
        }

        $parts = explode('/', substr($key, strlen($base)));

        return count($parts) >= 2 && $parts[0] !== '' ? $parts[0] : null;
    }

    public static function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? 'device';
    }
}
