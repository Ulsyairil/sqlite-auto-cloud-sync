<?php

declare(strict_types=1);

namespace SqliteSync\Core;

final class CompactionResult
{
    public int $deletedBatches = 0;
    public int $deletedAppliedRows = 0;

    /** @var list<string> */
    public array $keptBecause = [];

    /** @return array<string, int|list<string>> */
    public function toArray(): array
    {
        return [
            'deleted_batches' => $this->deletedBatches,
            'deleted_applied_rows' => $this->deletedAppliedRows,
            'kept_because' => $this->keptBecause,
        ];
    }
}
