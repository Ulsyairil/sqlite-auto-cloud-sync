<?php

declare(strict_types=1);

namespace SqliteSync\Core;

final class SnapshotResult
{
    /**
     * @param array<string, string> $sources
     */
    public function __construct(
        public readonly string $key,
        public readonly int $rows,
        public readonly int $bytes,
        public readonly array $sources = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'sources' => $this->sources,
        ];
    }
}
